<?php
declare(strict_types=1);

namespace Cjz\LaravelFilesystemOss;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperationFailed;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use League\Flysystem\Visibility;
use \Exception;
use OSS\OssClient;
class OssAdapter implements FilesystemAdapter ,ChecksumProvider,PublicUrlGenerator,TemporaryUrlGenerator
{
    protected $accessKeyId;

    protected $accessKeySecret;

    protected $endpoint;

    protected $bucket;

    protected  $isCName;

    /**
     * @var array
     */
    protected $buckets;

    /**
     * @var OssClient
     */
    protected $client;

    /**
     * @var array|mixed[]
     */
    protected $params;

    /**
     * @var bool
     */
    protected $useSSL = false;

    /**
     * @var string|null
     */
    protected $cdnUrl = null;

    protected $url = '';
    /**
     * @var PathPrefixer
     */
    protected $prefixer;

    private const EXTRA_METADATA_FIELDS = [
        'etag',
    ];

    public function __construct(
        $accessKeyId,
        $accessKeySecret,
        $endpoint,
        $bucket,
        private string $cdn = '',
        string $prefix = '',
        bool $isCName = false,
        array $buckets = [],
        private array $metadataFields = self::EXTRA_METADATA_FIELDS,
        ...$params
    )
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint = $endpoint;
        $this->bucket = $bucket;
        $this->isCName = $isCName;
        $this->prefixer = new PathPrefixer($prefix, DIRECTORY_SEPARATOR);
        $this->buckets = $buckets;
        $this->params = $params;
        $this->initClient();
        $this->checkEndpoint();
        $this->setBucketUrl();
    }

    public function setCdnUrl($url)
    {
        if (
            0 !== strpos($this->endpoint, 'http://')
            ||0 !== strpos($this->endpoint, 'https://')
        )
        {
            throw new Exception('请设置填写https://或http://');
        }
        $this->url = $url;
    }

    private function setBucketUrl()
    {
        $scheme = $this->useSSL?"https://":"http://";
        if(!empty($this->cdn)){
            $this->url = $this->cdn;
        }else{
            $this->url = sprintf("%s%s.%s",$scheme,$this->bucket,$this->endpoint);;
        }
    }
    protected function checkEndpoint()
    {
        if (0 === strpos($this->endpoint, 'http://')) {
            $this->endpoint = substr($this->endpoint, strlen('http://'));
            $this->useSSL = false;
        } elseif (0 === strpos($this->endpoint, 'https://')) {
            $this->endpoint = substr($this->endpoint, strlen('https://'));
            $this->useSSL = true;
        }
    }
    public function initClient()
    {
        $this->client = new OssClient($this->accessKeyId,$this->accessKeySecret,$this->endpoint,$this->isCName,...$this->params);
    }


    public function getClient():OssClient
    {
        return $this->client;
    }
    /**
     * @param string $bucket
     * @return $this
     * @throws \OSS\Core\OssException
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function bucket(string $bucket):self
    {
        $this->client->getBucketInfo($bucket);
        $this->bucket = $bucket;
        return $this;
    }
    /**
     * @param string $path
     * @return bool
     * @throws \OSS\Core\OssException
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function fileExists(string $path):bool
    {
        try {
            return $this->client->doesObjectExist($this->bucket, $this->prefixer->prefixPath($path));
        } catch (Exception $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);

        }

    }

    /**
     * @param string $path
     * @return bool
     * @throws \OSS\Core\OssException
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function directoryExists(string $path):bool
    {
        $directory = $this->prefixer->prefixPath($path);
//        var_dump($directory);
        try {
            $list = $this->client->listObjectsV2($this->bucket, [OssClient::OSS_PREFIX => $directory]);
//            $objectList = $list->getObjectList();
            $prefixList = $list->getPrefixList();
//            var_dump($objectList,$prefixList);
            return  !empty($prefixList);
        } catch (Exception $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);

        }
    }



    public function write(string $path,string $contents,Config $config):void
    {
        $path = $this->prefixer->prefixPath($path);
        $options = $this->createOptionsFromConfig($config);
        try {
            $this->client->putObject($this->bucket,$path,$contents,$options);
        }catch (\Exception $exception){
            throw UnableToWriteFile::atLocation($path,$exception->getMessage());
        }
    }

    public function writeStream(string $path, $contents,Config $config):void
    {
        $path = $this->prefixer->prefixPath($path);
        $options = $this->createOptionsFromConfig($config);
        try {
            $this->client->uploadStream($this->bucket,$path,$contents,$options);
        }catch (\Exception $exception){
            throw UnableToWriteFile::atLocation($path,$exception->getMessage());
        }
    }

    /**
     * @param string $path
     * @return string
     * @throws UnableToReadFile
     */
    public function read(string $path):string
    {
        $path = $this->prefixer->prefixPath($path);
        try {
             $res = $this->client->getObject($this->bucket,$path);
             return $res;
        }catch ( \Exception $exception){
            throw UnableToReadFile::fromLocation( $path,$exception->getMessage());
        }
    }

    public function readStream(string $path)
    {
        $path = $this->prefixer->prefixPath($path);
        $stream = fopen('php://temp','w+');


        try {
            $this->client->getObject($this->bucket, $path, [OssClient::OSS_FILE_DOWNLOAD => $stream]);
        } catch (\Exception $e) {
            fclose($stream);
            throw UnableToReadFile::fromLocation($path,$e->getMessage());
        }
        rewind($stream);#将指针指向头
        return $stream;
    }

    public function delete(string $path):void
    {
        $path = $this->prefixer->prefixPath($path);
        try {
            $this->client->deleteObject($this->bucket, $path);
        } catch (\Exception  $e) {
            throw UnableToDeleteFile::atLocation($path,$e->getMessage());
        }
    }

    public function listContents(string $path,bool $deep):iterable
    {
        $directory = $this->prefixer->prefixDirectoryPath($path);
        $startAfter = '';
        while (true){
            $options = [
                OssClient::OSS_PREFIX => $directory,
                OssClient::OSS_START_AFTER => $startAfter
            ];
            $listObjectInfo = $this->client->listObjectsV2($this->bucket,$options);
            $startAfter = $listObjectInfo->getStartAfter();
            $prefixList = $listObjectInfo->getPrefixList();

            $listObject = $listObjectInfo->getObjectList();
            foreach ($listObject as $objectInfo){
                $objectPath = $this->prefixer->stripPrefix($objectInfo->getKey());
                $objectLastModified = strtotime($objectInfo->getLastModified());
                if('/' == substr($objectPath,-1,1)){
                    continue;
                }
                yield new FileAttributes($objectPath,$objectInfo->getSize(),null,$objectLastModified);

            }
            foreach ($prefixList as $prefixInfo){
                $subPath = $this->prefixer->stripDirectoryPrefix($prefixInfo->getPrefix());
                if($subPath == rtrim($path,'\\/')){//目录本身不用管
                    continue;
                }
                yield new DirectoryAttributes($subPath);
                if(true === $deep){
                    $contents = $this->listContents($subPath,true);
                    foreach ($contents as $content){
                        yield $content;
                    }
                }
            }
            if('true' !== $listObjectInfo->getIsTruncated()){
                break;
            }

        }
    }

    public function deleteDirectory(string $path):void
    {
        try {
            $contents = $this->listContents($path, false);
            $files = [];
            $filesNum = 0;
            foreach ($contents as $i => $content) {
                if ($content instanceof DirectoryAttributes) {
                    $this->deleteDirectory($content->path());
                }else{
                    $filesNum++;
                    $files[] = $this->prefixer->prefixPath($content->path());
                    if ($filesNum && 0 == $filesNum % 100) {
                        $this->client->deleteObjects($this->bucket, $files);
                        $files = [];
                    }
                }

            }
            !empty($files) && $this->client->deleteObjects($this->bucket, $files);
            $this->client->deleteObject($this->bucket, $this->prefixer->prefixDirectoryPath($path));
        } catch (\Exception $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getErrorCode(), $e);

        }


    }

    public function createDirectory(string $path, Config $config): void
    {
        $directory  = $this->prefixer->prefixPath($path);
        try {
            $this->client->createObjectDir($this->bucket,$directory);
        }catch (Exception $ex){
            throw UnableToCreateDirectory::atLocation($path,$ex->getMessage());
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {

        $object = $this->prefixer->prefixPath($path);
        $acl = $this->visibilityToAcl($visibility);
        try {
            $this->client->putObjectAcl($this->bucket, $this->prefixer->prefixPath($path), $acl);
        } catch (Exception $e) {
            throw UnableToSetVisibility::atLocation($path,$e->getMessage());
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $object = $this->prefixer->prefixPath($path);
        try {
            $acl = $this->client->getObjectAcl($this->bucket, $object);
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::visibility($path,$e->getMessage());
        }
        $visibility = $this->aclToVisibility($acl);

        return  new FileAttributes($path,null,$visibility);
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $attribute = $this->fetchFileMetaData($path, FileAttributes::ATTRIBUTE_MIME_TYPE);
            if (
                $attribute->mimeType() === null
                || $attribute->mimeType()=='application/octet-stream'
            ) {
                throw new \Exception('no mimetype');
            }
        } catch (Exception $e) {
            throw UnableToRetrieveMetadata::mimeType($path,$e->getMessage());
        }
        return $attribute;

    }

    public function lastModified(string $path): FileAttributes
    {
        $attributes = $this->fetchFileMetadata($path, StorageAttributes::ATTRIBUTE_LAST_MODIFIED);

        if ($attributes->lastModified() === null) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return $attributes;
    }

    public function fileSize(string $path): FileAttributes
    {
        $attributes = $this->fetchFileMetadata($path, StorageAttributes::ATTRIBUTE_FILE_SIZE);

        if ($attributes->fileSize() === null) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return $attributes;
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            if($source !=$destination){
                $this->delete($source);
            }
        } catch (Exception $e) {
            throw UnableToMoveFile::fromLocationTo($source,$destination,$e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $fromObject = $this->prefixer->prefixPath($source);
        $toObject = $this->prefixer->prefixPath($destination);

        try {
            $options = $this->createOptionsFromConfig($config);
            $this->client->copyObject($this->bucket, $fromObject, $this->bucket, $toObject, $options);
        } catch (Exception $e) {
            throw UnableToCopyFile::fromLocationTo($source,$destination,$e);
        }
    }

    private function fetchFileMetaData(string $path,string $type):FileAttributes
    {
        $object = $this->prefixer->prefixPath($path);

        try {
            $result = $this->client->getObjectMeta($this->bucket, $object);
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::create($path,$type,$e->getMessage());
        }

        $attributes = $this->mapOssObjectMetadata($result,$path);

        if ( ! $attributes instanceof FileAttributes) {
            throw UnableToRetrieveMetadata::create($path, $type, '');
        }

        return $attributes;
//        if()

    }

    private function mapOssObjectMetadata(array $metadata,string $path):StorageAttributes
    {
        if (substr($path, -1) === '/') {
            return new DirectoryAttributes(rtrim($path, '/'));
        }
        $mimetype = $metadata['content-type']??null;
        $filesize = $metadata['content-length']?(int)$metadata['content-length']:null;
        $lastModified = $metadata['last-modified']?strtotime($metadata['last-modified']):null;
        return new FileAttributes(
            $path,
            $filesize,
            null,
            $lastModified,
            $mimetype,
            $this->extractExtraMetadata($metadata)
        );
    }

    private function extractExtraMetadata(array $metadata): array
    {
        $extracted = [];

        foreach ($this->metadataFields as $field) {
            if (isset($metadata[$field]) && $metadata[$field] !== '') {
                $extracted[$field] = $metadata[$field];
            }
        }

        return $extracted;
    }

    private function createOptionsFromConfig(Config $config): array
    {
        $acl = $config->get(Config::OPTION_VISIBILITY,'');
        if(!empty($acl)){
            $options[OssClient::OSS_HEADERS] = [OssClient::OSS_OBJECT_ACL=> $this->determineAcl($config) ];
        }
        return $options??[];
    }

    private function determineAcl(Config $config): string
    {
        $visibility = (string) $config->get(Config::OPTION_VISIBILITY, Visibility::PRIVATE);

        return $this->visibilityToAcl($visibility);
    }

    public function visibilityToAcl(string $visibility): string
    {

        if(  Visibility::PUBLIC == $visibility){
            return OssClient::OSS_ACL_TYPE_PUBLIC_READ;
        }
        return OssClient::OSS_ACL_TYPE_PRIVATE;
    }

    public function aclToVisibility(string $acl): string
    {
        if( OssClient::OSS_ACL_TYPE_PRIVATE == $acl){
            return Visibility::PRIVATE;
        }
        return Visibility::PUBLIC;
    }

    public function checksum(string $path, Config $config):string
    {
        $algo = $config->get('checksum_algo', 'etag');

        if ($algo !== 'etag') {
            throw new ChecksumAlgoIsNotSupported();
        }

        try {
            $metadata = $this->fetchFileMetadata($path, 'checksum')->extraMetadata();
        } catch (UnableToRetrieveMetadata $exception) {
            throw new UnableToProvideChecksum($exception->reason(), $path, $exception);
        }

        if ( ! isset($metadata['etag'])) {
            throw new UnableToProvideChecksum('ETag header not available.', $path);
        }

        return strtolower(trim($metadata['etag'], '"'));
    }

    public function publicUrl(string $path, Config $config): string{
        $objectPath = $this->prefixer->prefixPath($path);

        return  $this->url.'/'.$objectPath;
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, Config $config): string
    {
        $options = $this->createOptionsFromConfig($config);
        $objectPath = $this->prefixer->prefixPath($path);
        $timeout = $expiresAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        try {
            $url = $this->client->signUrl($this->bucket, $objectPath, $timeout, OssClient::OSS_HTTP_GET, $options);
        } catch (Exception $e) {
            throw UnableToGenerateTemporaryUrl::noGeneratorConfigured($path,$e->getMessage());
        }
        return  $this->url.'/'.$objectPath.'?'.explode('?',$url)[1];
    }

    public function getUrl($path)
    {
        $visibility = $this->visibility($path)->visibility();
        if(Visibility::PRIVATE == $visibility){
            $expiresAt = (new \DateTimeImmutable())->add(\DateInterval::createFromDateString('10 minute'));
            return $this->temporaryUrl($path,$expiresAt,new Config());
        }else{
            return $this->publicUrl($path,new Config());
        }
    }
}

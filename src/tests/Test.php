<?php

namespace Cjz\LaravelFilesystemOss\tests;

use Cjz\LaravelFilesystemOss\OssAdapter;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;

class Test extends FilesystemAdapterTestCase
{

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        error_reporting(E_ALL | E_STRICT | E_DEPRECATED);

        $ossSecret = getenv('OSS_SECRET');
        $ossKey    = getenv('OSS_KEY');
        $phpVersion = getenv('VERSION');
        $prefix     = str_replace('.','',$phpVersion).time();

        $endpoint= 'oss-cn-shanghai.aliyuncs.com';
        return  new OssAdapter($ossKey,$ossSecret,$endpoint,'cjz-file','',$prefix);
    }
}

<?php
namespace Cjz\LaravelFilesystemOss;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class OssStorageServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        Storage::extend('oss',function ($app,$config){
            $adapter =  new OssAdapter(
                $config['access_key'],
                $config['access_secret'],
                $config['endpoint'],
                $config['bucket'],
                $config['cdnDomain'],
                $config['prefix']
            );
            return new FilesystemAdapter(new Filesystem($adapter),$adapter);
        });
    }
}
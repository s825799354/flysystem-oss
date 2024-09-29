Flysystem adapter for the oss storage.
## 鸣谢
- [iidestiny/flysystem-oss](https://github.com/iiDestiny/flysystem-oss)



## 安装命令
 
```shell
$ composer require "cjz/laravel-flysystem-oss"
```
## 要求 php>=8.1 laravel>=10
## 配置
在 `config/filesystems.php` 配置文件中添加你的新驱动
```php
 <?php

return [
   'disks' => [
        'aliyunoss' => [ # 键名可以任意取名
            'driver' => 'oss',
            'access_key' => env('OSS_ACCESS_KEY'),
            'access_secret' => env('OSS_ACCESS_SECRET'),
            'bucket' => 'your_bucket',
            'endpoint' => 'https://oss-cn-shanghai.aliyuncs.com', // OSS 外网节点或自定义外部域名，这里需要自己拼写http和https协议 前缀。
            'cdnDomain' => '<CDN domain, cdn域名>', // bucket 绑定的cdn地址。
            'prefix' => '',//设置上传是的根前缀
        ],
    ]
];
```


## 常用方法

```php
use Illuminate\Support\Facades\Storage;

$storage = Storage::disk('aliyunoss'); 

Storage::put('1.txt','内容'); # 上传一个文件
Storage::put('1.txt','内容',[Config::OPTION_VISIBILITY=>Visibility::PRIVATE]);# 上传一个私有文件
$request->file('1.mp4')->store('1.mp4',['disk'=>'aliyunoss']);# 上传表单中的文件
Storage::url('1.txt');#获取文件对应的完整url


```
以上方法可在 [laravel 文件存储中查阅 ](https://learnku.com/docs/laravel/10.x/filesystem/14865) 查阅


## 获取官方完整 OSS 处理能力

阿里官方 SDK 可能处理了更多的事情，如果你想获取完整的功能可通过此插件获取，
然后你将拥有完整的 oss 处理能力


> 更多功能请查看官方 SDK 手册：https://help.aliyun.com/document_detail/32100.html?spm=a2c4g.11186623.6.1055.66b64a49hkcTHv



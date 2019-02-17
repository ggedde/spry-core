<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit8cc2bf99c5d6101a19b6e7fd3180fb37
{
    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Spry\\' => 5,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Spry\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Spry\\Spry' => __DIR__ . '/../..' . '/src/Spry.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit8cc2bf99c5d6101a19b6e7fd3180fb37::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit8cc2bf99c5d6101a19b6e7fd3180fb37::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit8cc2bf99c5d6101a19b6e7fd3180fb37::$classMap;

        }, null, ClassLoader::class);
    }
}
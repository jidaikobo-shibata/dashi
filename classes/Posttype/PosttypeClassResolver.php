<?php
namespace Dashi\Core\Posttype;

class PosttypeClassResolver
{
    /**
     * ファイルパスから posttype クラス名候補を作る
     *
     * @param string $filepath
     * @return string|null
     */
    public static function resolveClassNameFromFile($filepath)
    {
        $filename = basename((string) $filepath, '.php');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $filename))
        {
            return null;
        }

        return '\\Dashi\\Posttype\\' . ucfirst($filename);
    }

    /**
     * 現行実装どおり、クラス存在と __init の有無を確認する
     *
     * @param string $class
     * @return bool
     */
    public static function isLoadableClass($class)
    {
        if (!is_string($class) || $class === '')
        {
            return false;
        }

        if (!class_exists($class))
        {
            return false;
        }

        try
        {
            $ref = new \ReflectionClass($class);
            return $ref->hasMethod('__init');
        }
        catch (\ReflectionException $e)
        {
            return false;
        }
    }

    /**
     * 候補ファイル一覧から読み込み対象クラスを集める
     *
     * @param array<int, string> $posttype_files
     * @return array<int, string>
     */
    public static function collect($posttype_files)
    {
        $posttypes = [];

        foreach ($posttype_files as $filepath)
        {
            $class = static::resolveClassNameFromFile($filepath);
            if ($class === null)
            {
                continue;
            }

            if (!static::isLoadableClass($class))
            {
                continue;
            }

            $posttypes[] = $class;
        }

        return $posttypes;
    }
}

<?php
namespace Dashi\Core\Posttype;

class PosttypeFileLocator
{
    /**
     * posttype 定義ファイルの探索
     *
     * 子テーマを優先し、同名ファイルは親テーマ側を読み込まない。
     *
     * @param string $stylesheet_dir
     * @param string $template_dir
     * @return array<int, string>
     */
    public static function locate($stylesheet_dir, $template_dir)
    {
        $posttype_files = static::collectFromDirectory($stylesheet_dir . '/posttype');

        $loaded_basenames = array_map('basename', $posttype_files);
        if ($stylesheet_dir !== $template_dir)
        {
            foreach (static::collectFromDirectory($template_dir . '/posttype') as $filepath)
            {
                if (in_array(basename($filepath), $loaded_basenames, true))
                {
                    continue;
                }
                $posttype_files[] = $filepath;
            }
        }

        return $posttype_files;
    }

    /**
     * 単一ディレクトリから有効な posttype 定義だけを集める
     *
     * @param string $dir
     * @return array<int, string>
     */
    private static function collectFromDirectory($dir)
    {
        if (!is_dir($dir))
        {
            return [];
        }

        $base_dir = realpath($dir);
        if ($base_dir === false)
        {
            return [];
        }

        $posttype_files = [];
        foreach (glob($dir . '/*.php') as $filepath)
        {
            $filename = basename($filepath);
            if (!preg_match('/^[A-Za-z0-9_]+\.php$/', $filename))
            {
                continue;
            }

            $real = realpath($filepath);
            if ($real === false || strpos($real, $base_dir) !== 0)
            {
                continue;
            }

            $posttype_files[] = $real;
        }

        return $posttype_files;
    }
}

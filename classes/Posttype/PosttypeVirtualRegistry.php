<?php

namespace Dashi\Core\Posttype;

/**
 * Dashi が定義していない既存の投稿タイプも、Dashi の posttype クラスとして扱うための薄い橋渡し。
 *
 * ここでいう virtual post type は、新しい投稿タイプを追加する仕組みではなく、
 * DB 上にすでに存在する post_type に対して `Dashi\Posttype\Xxx` という仮想クラスを与え、
 * `Posttype::posttype2class()` や `Posttype::instances()` のような
 * クラス前提の API に載せるための互換レイヤです。
 *
 * 実体は `Dashi\Core\Posttype\Virtual` を alias しただけの最小クラスで、
 * `is_dashi = false` にしておくことで、Dashi 独自の投稿タイプ登録や
 * 専用アセット読み込みは行わず、参照系の処理だけに参加させます。
 */
class PosttypeVirtualRegistry
{
    /**
     * DB 行から virtual post type 候補を集める
     *
     * @param array<int, object> $rows
     * @param array<int, string> $known_classes
     * @return array{classes: array<int, string>, posttypes: array<int, string>}
     */
    public static function collect($rows, $known_classes)
    {
        $classes = [];
        $posttypes = [];

        foreach ($rows as $row)
        {
            if (!isset($row->post_type) || !is_string($row->post_type))
            {
                continue;
            }

            $posttype = $row->post_type;
            if (!static::isSupportedPosttype($posttype))
            {
                continue;
            }

            $class = static::resolveClassName($posttype);
            if (in_array($class, $known_classes, true) || in_array($class, $classes, true))
            {
                continue;
            }

            $classes[] = $class;
            $posttypes[] = $posttype;
        }

        return [
            'classes' => $classes,
            'posttypes' => $posttypes,
        ];
    }

    /**
     * virtual post type の alias を登録する
     *
     * @param string $posttype
     * @return bool
     */
    public static function register($posttype)
    {
        if (!static::isSupportedPosttype($posttype))
        {
            return false;
        }

        $virtual_class = static::resolveClassName($posttype);
        $base_class = 'Dashi\\Core\\Posttype\\Virtual';

        if (!class_exists($virtual_class))
        {
            class_alias($base_class, $virtual_class);
        }

        return true;
    }

    /**
     * virtual 化の対象にできる post type 名かを確認する
     *
     * @param string $posttype
     * @return bool
     */
    public static function isSupportedPosttype($posttype)
    {
        if (!is_string($posttype) || $posttype === '')
        {
            return false;
        }

        if (in_array($posttype, ['revision', 'attachment'], true))
        {
            return false;
        }

        if (strpos($posttype, '-') !== false)
        {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $posttype);
    }

    /**
     * post type 名から virtual クラス名を作る
     *
     * @param string $posttype
     * @return string
     */
    public static function resolveClassName($posttype)
    {
        return 'Dashi\\Posttype\\' . ucfirst($posttype);
    }
}

<?php
if (!defined('ABSPATH')) exit;
    get_header();
?>

<h1><?php echo esc_html(wp_get_document_title()); ?></h1>

<?php
// errors
// エラーがあったら表示。再訪問ステップの場合は、エラーを表示しない
if (isset($errors[$step]) && is_array($errors[$step]) && $is_visited):
$dashi_html = '';
foreach ($errors[$step] as $dashi_err => $dashi_fields):
    foreach ($dashi_fields as $dashi_field):
        if ( ! isset($steps[$step][$dashi_field])) continue;
        $dashi_message = sprintf(
            \Dashi\Core\Validation::getMessage($dashi_err),
            $steps[$step][$dashi_field]['label']
        );
        $dashi_id = \Dashi\Core\Form\Field::getId($dashi_field);
        $dashi_html.= '    <li><a href="#'.$dashi_id.'">'.$dashi_message.'</a></li>'."\n";
    endforeach;
endforeach;
echo '<ul class="dashi_errors">'.wp_kses_post($dashi_html).'</ul>';
endif;

// form or messages
echo wp_kses_post($dashi_body);

get_footer();

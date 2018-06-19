<?php
	get_header();
?>

<h1><?php echo wp_get_document_title() ?></h1>

<?php
// errors
// エラーがあったら表示。再訪問ステップの場合は、エラーを表示しない
if (isset($errors[$step]) && is_array($errors[$step]) && $is_visited):
$html = '';
foreach ($errors[$step] as $err => $fields):
	foreach ($fields as $field):
		if ( ! isset($steps[$step][$field])) continue;
		$message = sprintf(
			__(\Dashi\Core\Validation::getMessage($err), 'dashi'),
			$steps[$step][$field]['label']
		);
		$id = \Dashi\Core\Form\Field::getId($field);
		$html.= '	<li><a href="#'.$id.'">'.$message.'</a></li>'."\n";
	endforeach;
endforeach;
echo '<ul class="dashi_errors">'.$html.'</ul>';
endif;

// form or messages
echo $dashi_body;

get_footer();

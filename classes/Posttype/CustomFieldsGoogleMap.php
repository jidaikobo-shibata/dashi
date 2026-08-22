<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class CustomFieldsGoogleMap
{
	/**
	 * draw
	 *
	 * @param  Array|Object $object (wp_post object or posted value)
	 * @param  Array $value
	 * @param  Bool  $is_public_form
	 * @return  void
	 */
	public static function draw ($object, $value, $is_public_form = false)
	{
		// base value
		$id = sanitize_key($value['id']);
		$place_id = 'place_'.$id;
		$place_btn_id = 'place_btn_'.$id;
		$map_id = 'map_'.$id;

		// value from dashi
		$class = P::posttype2class($object->post_type);
		$custom_fields = $class::get('custom_fields');

		$dashi = isset($custom_fields[$id]) ? $custom_fields[$id] : array();

		// value
		$place = '';
		$lat = '';
		$lng = '';
		$zoom = 13;

		if (is_object($object) && isset($object->ID))
		{
			$metas = get_post_meta($object->ID, $id, true);
			$place = isset($metas['place']) ? $metas['place'] : $place;
			$lat   = isset($metas['lat']) ? $metas['lat'] : $lat;
			$lng   = isset($metas['lng']) ? $metas['lng'] : $lng;
			$zoom  = isset($metas['zoom']) ? intval($metas['zoom']) : $zoom;
		}

		// overwrite if value already exists
		if (property_exists($object, $id) && is_array($object->$id))
		{
			$place = isset($object->$id['place']) ? $object->$id['place'] : $place;
			$lat   = isset($object->$id['lat']) ? $object->$id['lat'] : $lat;
			$lng   = isset($object->$id['lng']) ? $object->$id['lng'] : $lng;
			$zoom  = isset($object->$id['zoom']) ? intval($object->$id['zoom']) : $zoom;
		}

		$place = sanitize_text_field((string) $place);
		$lat = self::normalizeCoordinate($lat, -90, 90);
		$lng = self::normalizeCoordinate($lng, -180, 180);
		$zoom = min(21, max(1, (int) $zoom));

		$place_name = $id.'[place]';
		$lat_name   = $id.'[lat]';
		$lng_name   = $id.'[lng]';
		$zoom_name  = $id.'[zoom]';

		if ( ! get_option('dashi_google_map_api_key'))
		{
	            echo '<strong style="color: #f00;background-color: #fff;">'.esc_html__('Google Map is disabled. Enter coordinates manually.', 'dashi').'</strong>';

	            echo '<table class="form-table"><tr><th><label for="lat_'.esc_attr($map_id).'">'.esc_html__('latitude', 'dashi').'</label></th><td>';
	            echo '<input type="text" name="'.esc_attr($lat_name).'" id="lat_'.esc_attr($map_id).'" value="'.esc_attr($lat).'" />';
	            echo '</td></tr><tr><th><label for="lng_'.esc_attr($map_id).'">'.esc_html__('longitude', 'dashi').'</label></th><td>';
	            echo '<input type="text" name="'.esc_attr($lng_name).'" id="lng_'.esc_attr($map_id).'" value="'.esc_attr($lng).'" />';
	            echo '</td></tr><tr><th><label for="zoom_'.esc_attr($map_id).'">'.esc_html__('zoom (1-21)', 'dashi').'</label></th><td>';
	            echo '<input type="number" name="'.esc_attr($zoom_name).'" id="zoom_'.esc_attr($map_id).'" value="'.esc_attr($zoom).'" min="1" max="21" style="width: 4rem;" />';
            echo '</td></tr></table>';
            return;
		}
?>

<!-- Google Map -->
<table style="min-width: 80%;">
<tr>
<td>
		<ul>
				<li><?php echo esc_html__('Input address and press search button. Then map will be place near point.', 'dashi'); ?></li>
				<li><?php echo esc_html__('Move map by mouse to adjust certain place.', 'dashi'); ?></li>
		<?php
			if (isset($dashi['description']) && $dashi['description'])
			{
					echo '<li>'.wp_kses_post($dashi['description']).'</li>';
			}
		?>
		</ul>
</td>
</tr>
<tr>
	<td>
		<label for="<?php echo esc_attr($place_id); ?>"><?php echo esc_html__('Place', 'dashi'); ?></label>
		<input type="text" style="width: 80%;" name="<?php echo esc_attr($place_name); ?>" id="<?php echo esc_attr($place_id); ?>" value="<?php echo esc_attr($place); ?>" />
		<input type="button" id="<?php echo esc_attr($place_btn_id); ?>" value="<?php echo esc_attr__('Search', 'dashi'); ?>" />
	</td>
</tr>

<tr>
	<td>
		<div id="<?php echo esc_attr($map_id); ?>" style="width:100%; height:300px;margin:0;padding:0;"></div>
		<script type="text/javascript">
		<!--
		jQuery(function() {
			var marker = '';
		<?php if ($lat !== '' && $lng !== ''): ?>
			var latLng = new google.maps.LatLng(<?php echo wp_json_encode($lat); ?>, <?php echo wp_json_encode($lng); ?>);
			var czoom = <?php echo intval($zoom) ?>;
		<?php else: ?>
			var latLng = new google.maps.LatLng(35.3605555,138.72777769999993);
			var czoom = 4;
		<?php endif; ?>
			var myOptions = {
				zoom: czoom,
				center: latLng,
				scrollwheel: true,
				disableDoubleClickZoom: true,
				mapTypeControlOptions: {style: google.maps.MapTypeControlStyle.DROPDOWN_MENU},
				mapTypeId: google.maps.MapTypeId.ROADMAP
			};
			var geocoder = new google.maps.Geocoder();
			var map = new google.maps.Map(jQuery("#<?php echo esc_js($map_id); ?>").get(0), myOptions);
			var marker = new google.maps.Marker({
				position:latLng,
				map: map,
				title: 'Point!',
				draggable: true
			});

		jQuery("#<?php echo esc_js($place_btn_id); ?>").click(function() {
			if (marker != null) {
				marker.setVisible(false);
				delete marker;
			}
			if (geocoder) {
				geocoder.geocode({'address': jQuery("#<?php echo esc_js($place_id); ?>").val()}, function(results, status) {
					if (status == google.maps.GeocoderStatus.OK) {
						map.setCenter(results[0].geometry.location);
						marker = new google.maps.Marker({
							map: map,
							position: results[0].geometry.location,
							draggable: true
						});
						jQuery('#lat_<?php echo esc_js($map_id); ?>').attr('value',results[0].geometry.location.lat());
						jQuery('#lng_<?php echo esc_js($map_id); ?>').attr('value',results[0].geometry.location.lng());
						google.maps.event.addListener(marker, 'drag', function() {
						updateMarkerPosition(marker.getPosition());
						});
					} else {
						alert(<?php echo wp_json_encode(__('Geocoder failed due to', 'dashi').': '); ?> + status);
					}
				});
			}
		});

		google.maps.event.addListener(map, 'dblclick', function(event) {
			updateMarkerPosition(event.latLng) ;
			marker.setPosition( event.latLng ) ;
		});

		google.maps.event.addListener(marker, 'drag', function() {
			updateMarkerPosition(marker.getPosition());
		});

		function updateMarkerPosition(latLng) {
			jQuery('#lat_<?php echo esc_js($map_id); ?>').attr('value',[latLng.lat()]) ;
			jQuery('#lng_<?php echo esc_js($map_id); ?>').attr('value',[latLng.lng()]) ;
		}

        google.maps.event.addListener(map, 'zoom_changed', function() {
            var currentZoom = map.getZoom();
	            jQuery('#zoom_<?php echo esc_js($map_id); ?>').val(currentZoom);
        });

		});
		// -->
		</script>
	</td>
</tr>
<tr>
		<td><label for="lat_<?php echo esc_attr($map_id); ?>"><?php echo esc_html__('latitude', 'dashi'); ?></label>
			<input type="text" id="lat_<?php echo esc_attr($map_id); ?>" name="<?php echo esc_attr($lat_name); ?>" value="<?php echo esc_attr($lat); ?>" /></td>
</tr>
<tr>
		<td><label for="lng_<?php echo esc_attr($map_id); ?>"><?php echo esc_html__('longitude', 'dashi'); ?></label>
		<input type="text" id="lng_<?php echo esc_attr($map_id); ?>" name="<?php echo esc_attr($lng_name); ?>" value="<?php echo esc_attr($lng); ?>" /></td>
</tr>
<?php if ( ! $is_public_form): ?>
<tr>
		<td><label for="zoom_<?php echo esc_attr($map_id); ?>"><?php echo esc_html__('zoom (1-21)', 'dashi'); ?></label>
		<input type="number" id="zoom_<?php echo esc_attr($map_id); ?>" style="width: 3rem;" name="<?php echo esc_attr($zoom_name); ?>" value="<?php echo intval($zoom); ?>" min="1" max="21" /></td>
</tr>
<?php endif; ?>

<!-- /Google Map -->
</table>
<?php if ($is_public_form): ?>
<input type="hidden" name="<?php echo esc_attr($zoom_name); ?>" value="<?php echo intval($zoom); ?>" />
<?php endif; ?>

	<?php
		}

	private static function normalizeCoordinate($value, $min, $max)
	{
		if (!is_numeric($value)) return '';

		$value = (float) $value;
		if (!is_finite($value) || $value < $min || $value > $max) return '';

		return $value;
	}
	}

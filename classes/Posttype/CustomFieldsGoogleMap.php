<?php
namespace Dashi\Core\Posttype;

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
		if ( ! get_option('dashi_google_map_api_key'))
		{
			echo '<strong style="color: #f00;background-color: #fff;">Set Google API key</strong>';
			return;
		}

		// base value
		$id = $value['id'];
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

		$place_name = $id.'[place]';
		$lat_name   = $id.'[lat]';
		$lng_name   = $id.'[lng]';
		$zoom_name  = $id.'[zoom]';
?>

<!-- Google Map -->
<table style="min-width: 80%;">
<tr>
<td>
		<ul>
			<li><?php echo __('Input address and press search button. Then map will be place near point.', 'dashi'); ?></li>
			<li><?php echo __('Move map by mouse to adjust certain place.', 'dashi'); ?></li>
		<?php
			if (isset($dashi['description']) && $dashi['description'])
			{
				echo '<li>'.$dashi['description'].'</li>';
			}
		?>
		</ul>
</td>
</tr>
<tr>
	<td>
		<label for="<?php echo $place_id ?>"><?php echo __('Place', 'dashi'); ?></label>
		<input type="text" style="width: 80%;" name="<?php echo $place_name ?>" id="<?php echo $place_id ?>" value="<?php echo $place ?>" />
		<input type="button" id="<?php echo $place_btn_id ?>" value="<?php echo __('Search'); ?>" />
	</td>
</tr>

<tr>
	<td>
		<div id="<?php echo $map_id ?>" style="width:100%; height:300px;margin:0;padding:0;"></div>
		<script type="text/javascript">
		<!--
		jQuery(function() {
			var marker = '';
		<?php if( $lat ): ?>
			var latLng = new google.maps.LatLng(<?php echo esc_html( $lat ) ?>, <?php echo esc_html( $lng ) ?>);
			var czoom = <?php echo intval($zoom) ?>;
		<?php else: ?>
			var latLng = new google.maps.LatLng(35.3605555,138.72777769999993);
			var czoom = 4;
		<?php endif; ?>
			var myOptions = {
				zoom: czoom,
				center: latLng,
				scrollwheel: false,
				disableDoubleClickZoom: true,
				mapTypeControlOptions: {style: google.maps.MapTypeControlStyle.DROPDOWN_MENU},
				mapTypeId: google.maps.MapTypeId.ROADMAP
			};
			var geocoder = new google.maps.Geocoder();
			var map = new google.maps.Map(jQuery("#<?php echo $map_id ?>").get(0), myOptions);
			var marker = new google.maps.Marker({
				position:latLng,
				map: map,
				title: 'Point!',
				draggable: true
			});

		jQuery("#<?php echo $place_btn_id ?>").click(function() {
			if (marker != null) {
				marker.setVisible(false);
				delete marker;
			}
			if (geocoder) {
				geocoder.geocode({'address': jQuery("#<?php echo $place_id ?>").val()}, function(results, status) {
					if (status == google.maps.GeocoderStatus.OK) {
						map.setCenter(results[0].geometry.location);
						marker = new google.maps.Marker({
							map: map,
							position: results[0].geometry.location,
							draggable: true
						});
						jQuery('#lat_<?php echo $map_id ?>').attr('value',results[0].geometry.location.lat());
						jQuery('#lng_<?php echo $map_id ?>').attr('value',results[0].geometry.location.lng());
						google.maps.event.addListener(marker, 'drag', function() {
						updateMarkerPosition(marker.getPosition());
						});
					} else {
						alert("<?php echo __('Geocoder failed due to'); ?>: " + status);
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
			jQuery('#lat_<?php echo $map_id ?>').attr('value',[latLng.lat()]) ;
			jQuery('#lng_<?php echo $map_id ?>').attr('value',[latLng.lng()]) ;
		}
		});
		// -->
		</script>
	</td>
</tr>
<tr>
	<td><label for="lat_<?php echo $map_id ?>"><?php echo __('latitude', 'dashi') ?></label>
		<input type="text" id="lat_<?php echo $map_id ?>" name="<?php echo $lat_name ?>" value="<?php echo esc_html( $lat ) ?>" /></td>
</tr>
<tr>
	<td><label for="lng_<?php echo $map_id ?>"><?php echo __('longitude', 'dashi') ?></label>
	<input type="text" id="lng_<?php echo $map_id ?>" name="<?php echo $lng_name ?>" value="<?php echo esc_html( $lng ) ?>" /></td>
</tr>
<?php if ( ! $is_public_form): ?>
<tr>
	<td><label for="zoom"><?php echo __('zoom (1-21)', 'dashi') ?></label>
	<input type="text" id="zoom" name="<?php echo $zoom_name ?>" value="<?php echo intval( $zoom ) ?>" /></td>
</tr>
<?php endif; ?>

<!-- /Google Map -->
</table>
<?php if ($is_public_form): ?>
<input type="hidden" name="<?php echo $zoom_name ?>" value="<?php echo intval( $zoom ) ?>" />
<?php endif; ?>

<?php
	}
}

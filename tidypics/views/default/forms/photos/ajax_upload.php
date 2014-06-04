<?php
/**
 * Tidypics ajax upload form body
 *
 * @uses $vars['entity']
 */
$album = $vars ['entity'];

$ts = time ();
$batch = time ();
$tidypics_token = md5 ( session_id () . get_site_secret () . $ts . elgg_get_logged_in_user_entity ()->salt );
$basic_uploader_url = current_page_url () . '/basic';

$maxfilesize = ( float ) elgg_get_plugin_setting ( 'maxfilesize', 'tidypics' );
if (! $maxfilesize) {
	$maxfilesize = 5;
}

?>

<p>
<?php
echo elgg_echo ( 'tidypics:uploader:instructs', array (
		$maxfilesize,
		$basic_uploader_url 
) );
?>
</p>



<ul id="tidypics-uploader-steps">
	<li class="mbm">
	<input type="hidden" name="album_guid" value="<?php echo $album->getGUID(); ?>" /> <input type="hidden" name="batch"
					value="<?php echo $batch; ?>" /> <input type="hidden" name="tidypics_token" value="<?php echo $tidypics_token; ?>" /> <input type="hidden" name="user_guid"
					value="<?php echo elgg_get_logged_in_user_guid(); ?>" /> <input type="hidden" name="Elgg" value="<?php echo session_id(); ?>" />
		<div id="uploader">
			<p>Your browser doesn't have Flash, Silverlight or HTML5 support.</p>
		</div>
	</li>

</ul>

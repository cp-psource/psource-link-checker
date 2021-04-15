<!-- Advertising -->
<?php
$configuration = blc_get_configuration();
if ( !$configuration->get('user_has_donated') ):
?>
	<div id="managewp-ad" class="postbox">
		<div class="inside">
			<a href="https://n3rds.work/piestingtal-source-project/" title="PSOURCE POWER">
				<img src="<?php echo plugins_url('images/mwp250_2.jpg', BLC_PLUGIN_FILE) ?>" width="250" height="250" alt="ManageWP">
			</a>
		</div>
	</div>
<?php
endif; ?>
<?php
/*
Plugin Name: MindValley Widget Snapshot
Plugin URI: http://mindvalley.com/opensource
Description: Takes snapshots , enable import and export widget settings & configurations
Author: MindValley
Version: 1.0
*/

define('MV_WSS_DB_VERSION','0.1');

class MV_WidgetSnapshot {
	function __construct(){
		global $wpdb;
		$wpdb->mv_widgetsnapshot = $wpdb->prefix . 'mv_widgetsnapshot';;
		
		//update_option('mv_wss_db_version',0);
		if( get_option('mv_wss_db_version',0) < MV_WSS_DB_VERSION ){
			$this->setupTables();
			update_option('mv_wss_db_version',MV_WSS_DB_VERSION);
		}
		
		add_action('widgets_admin_page', array( &$this, 'widgets_admin_page'));
		add_action('init', array( &$this, 'init'));
		
	}
	
	function setupTables(){
		global $wpdb; 

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		
		// add charset & collate like wp core
		$charset_collate = '';

		if ( version_compare(mysql_get_server_info(), '4.1.0', '>=') ) {
			if ( ! empty($wpdb->charset) )
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty($wpdb->collate) )
				$charset_collate .= " COLLATE $wpdb->collate";
		}
		
		$mv_widgetsnapshot = $wpdb->mv_widgetsnapshot;

		$sql = "CREATE TABLE " . $mv_widgetsnapshot . " (
			ID BIGINT(20) NOT NULL AUTO_INCREMENT ,
			settings LONGTEXT NOT NULL,
			date TIMESTAMP NOT NULL DEFAULT now(),
			PRIMARY KEY (id)
			) $charset_collate;";

		dbDelta($sql);
	}
	
	function init(){
		if(isset($_POST['widget_export']) && isset($_POST['_widget_wss_nonce']) && wp_verify_nonce($_POST['_widget_wss_nonce'], 'mv_widget_snapshot_action'))
			$this->export();
		
		if(isset($_POST['widget_import']) && isset($_POST['_widget_wss_nonce']) && wp_verify_nonce($_POST['_widget_wss_nonce'], 'mv_widget_snapshot_action'))
			$this->import();
		
		if(isset($_POST['widget_takesnapshot']) && isset($_POST['_widget_wss_nonce']) && wp_verify_nonce($_POST['_widget_wss_nonce'], 'mv_widget_snapshot_action'))
			$this->takeSnapshot();
			
		if(isset($_POST['widget_loadsnapshot']) && isset($_POST['_widget_wss_nonce']) && wp_verify_nonce($_POST['_widget_wss_nonce'], 'mv_widget_snapshot_action'))
			$this->loadSnapshot();
			
		if(isset($_POST['widget_deletesnapshot']) && isset($_POST['_widget_wss_nonce']) && wp_verify_nonce($_POST['_widget_wss_nonce'], 'mv_widget_snapshot_ajax_action'))
			$this->deleteSnapshot();
	}
	
	function deleteSnapshot(){
		global $wpdb;
		
		if($wpdb->query("DELETE FROM {$wpdb->mv_widgetsnapshot} WHERE ID = " . $_POST['snapshot_id'])){
			echo json_encode(array('success' => 1));
		}else{
			echo json_encode(array('success' => 0));
		}
		 exit;
	}
	
	function takeSnapshot(){
		global $wpdb;
		
		$widgets = $wpdb->get_results( "SELECT option_name, option_value, blog_id, autoload FROM {$wpdb->options} WHERE option_name like 'widget_%'" );
		$settings = array();
		foreach($widgets as $widget){
			$tmp = array();
			$tmp['option_name'] = $widget->option_name;
			$tmp['blog_id'] = $widget->blog_id;
			$tmp['option_value'] = $widget->option_value;
			$tmp['autoload'] = $widget->autoload;
			$settings[] = $tmp;
		}
		
		$data = array('settings' => serialize($settings));
		
		$wpdb->insert($wpdb->mv_widgetsnapshot, $data);
		
		wp_redirect( admin_url('widgets.php') ); exit; 
	}
	
	function loadSnapshot(){
		global $wpdb;
		
		if($settings = $wpdb->get_var("SELECT settings FROM {$wpdb->mv_widgetsnapshot} WHERE ID = " . $_POST['snapshot_id'])){
			$this->importSettings(unserialize($settings));
		}
		
		wp_redirect( admin_url('widgets.php') ); exit; 
	}
	
	function export(){
		global $wpdb;
		
		$widgets = $wpdb->get_results( "SELECT option_name, option_value, blog_id, autoload FROM {$wpdb->options} WHERE option_name like 'widget_%'" );
		
		$xml = '<?xml version="1.0" encoding="ISO-8859-1"?><widget_settings>';
		foreach($widgets as $widget){
			$xml .= '<node>';
			$xml .= '<option_name>'.$widget->option_name.'</option_name>';
			$xml .= '<blog_id>'.$widget->blog_id.'</blog_id>';
			$xml .= '<option_value><![CDATA['.$widget->option_value.']]></option_value>';
			$xml .= '<autoload>'.$widget->autoload.'</autoload>';
			$xml .= '</node>';
		}
		
		$xml .= '</widget_settings>';
	
		
		header("content-type: text/xml");
		header("Content-Disposition: attachment; filename=widget_settings.xml");
		header('Content-Transfer-Encoding: binary');

		echo $xml; exit;
	}
	
	function import(){
	
		require_once(dirname(__FILE__). "/xml2array.php");
		if($_FILES['widget_import_xml']['error'] == 0){
			$xml = file_get_contents($_FILES['widget_import_xml']['tmp_name']);
			$settings = xml2array($xml);
			
			$this->importSettings($settings['widget_settings']['node']);
		}
		wp_redirect( admin_url('widgets.php') ); exit; 
	}
	
	function importSettings($settings){
		global $wpdb;
		
		if(empty($settings) || !is_array($settings))
			return false;
			
		foreach($settings as $setting){
			$option_name = $setting['option_name'];
			$blog_id = $setting['blog_id'];
			$option_value = $setting['option_value'];
			$autoload = $setting['autoload'];
			
			$option_id = $wpdb->get_var("SELECT option_id FROM {$wpdb->options} WHERE option_name like '{$option_name}'");

			if($option_id) {
				
				$data = array('option_value'=>$option_value, 'blog_id'=>$blog_id, 'autoload'=>$autoload );
				$wpdb->update($wpdb->options, $data, array('option_id' => $option_id ));
			}else{
				$data = array('option_name'=>$option_name,'option_value'=>$option_value, 'blog_id'=>$blog_id, 'autoload'=>$autoload );
				$wpdb->insert($wpdb->options, $data);
			}
		}
	}
	
	function widgets_admin_page(){
		?>
		<div style="clear:both">
			<form action="" method="post" enctype="multipart/form-data">
				<strong>Widget Snapshot :</strong>
				<?php wp_nonce_field('mv_widget_snapshot_action','_widget_wss_nonce'); ?>
				<input type="file" name="widget_import_xml">
				<input type="submit" name="widget_import" class="button" value="Import Widget Settings" onclick="if(!confirm('Warning!!! Importing will overwrite current settings. Press OK to proceed.')) return false;">
				<input type="submit" name="widget_export" class="button" value="Export Widget Settings">
				<input type="submit" name="widget_takesnapshot" class="button" value="Take Snapshot">
				<input type="button" class="button" value="Previous Snapshots" onclick="jQuery('#mv_snapshot_list').slideToggle()">
				<div id="mv_snapshot_list" style="display:none;height:250px;overflow:hidden;">
					<script>
						function removeSnapshot(me, id){
							jQuery.post('<?php echo admin_url()?>',
										{ 	_widget_wss_nonce: '<?php echo wp_create_nonce('mv_widget_snapshot_ajax_action'); ?>',
											snapshot_id: id,
											widget_deletesnapshot: '1'
										},
										function(data){
											if(data.success){
												jQuery(me).parent().fadeOut(function(){jQuery(this).remove();});
											}
										}, "json");
						}
					</script>
					<div style="height:200px;overflow:auto;margin:10px 0">
					<?php
						global $wpdb;
						$snapshots = $wpdb->get_results("SELECT * FROM {$wpdb->mv_widgetsnapshot} ORDER BY date DESC");
						if(!empty($snapshots))
						foreach($snapshots as $ss){
							?>
							<div>
								<input type="radio" name="snapshot_id" value="<?php echo $ss->ID;?>" id="snapshot_<?php echo $ss->ID;?>"> 
								<label for="snapshot_<?php echo $ss->ID;?>">Captured on <em><?php echo date('d/m/Y H:i:s', strtotime($ss->date));?></em></label>
								<input type="button" class="button" value="remove" onclick="if(confirm('Remove this snapshot?')) removeSnapshot(this,<?php echo $ss->ID;?>);">
							</div>
							<?php
						}
					?>
					</div>
					<input type="submit" name="widget_loadsnapshot" class="button" value="Load Selected Snapshot">
				</div>
			</form>
		</div>
		<?php
	}
}
new MV_WidgetSnapshot();
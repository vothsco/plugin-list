<?php
/**
Plugin Name: List Plugins
Plugin Tag: list, plugin, active
Description: <p>Create a list of the active plugins in a page (when the shortcode <code>[list_plugins]</code> is found). </p><p> The list may contain: </p><ul><li>the name of the plugin, </li><li>the description, </li><li>the version, </li><li>the screenshots,</li><li>a link to download the zip file of the current version.</li></ul><p>Plugin developped from the orginal plugin <a href="http://wordpress.org/plugins/wp-pluginsused/">WP-PluginsUsed</a>. </p><p>This plugin is under GPL licence. </p>
Version: 1.4.4

Framework: SL_Framework
Author: SedLex
Author Email: sedlex@sedlex.fr
Framework Email: sedlex@sedlex.fr
Author URI: http://www.sedlex.fr/
Plugin URI: http://wordpress.org/plugins/list-plugins/
License: GPL3
*/

require_once('core.php') ; 

if (!class_exists('PclZip'))
	require_once (ABSPATH."wp-admin/includes/class-pclzip.php");
if (!function_exists('get_plugins'))
	require_once (ABSPATH."wp-admin/includes/plugin.php");
			

class listplugins extends pluginSedLex {
	/** ====================================================================================================================================================
	* Initialisation du plugin
	* 
	* @return void
	*/
	static $instance = false ; 
	
	var $wp_plugins ;
	var $plugins_used ;
	var $pluginsused_hidden_plugins ;

	protected function _init() {
		global $wpdb ; 
		// Configuration
		$this->pluginName = 'List Plugins' ; 
		$this->table_name = $wpdb->prefix . "pluginSL_" . get_class() ; 
		$this->tableSQL = "" ; 
		$this->path = __FILE__ ; 
		$this->pluginID = get_class() ; 
		
		//Init et des-init
		register_activation_hook(__FILE__, array($this,'install'));
		register_deactivation_hook(__FILE__, array($this,'deactivate'));
		register_uninstall_hook(__FILE__, array('listplugins','uninstall_removedata'));
		
		//Parametres supplementaires
		add_shortcode( "list_plugins", array($this,"list_plugins") );

		$this->wp_plugins = array();
		$this->plugins_used = array() ;
		$this->pluginsused_hidden_plugins = array() ;
	}
	
	/**
	 * Function to instantiate our class and make it a singleton
	 */
	public static function getInstance() {
		if ( !self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}
	
	/** ====================================================================================================================================================
	* In order to uninstall the plugin, few things are to be done ... 
	* (do not modify this function)
	* 
	* @return void
	*/
	
	public function uninstall_removedata () {
		global $wpdb ;
		// DELETE OPTIONS
		delete_option('listplugins'.'_options') ;
		if (is_multisite()) {
			delete_site_option('listplugins'.'_options') ;
		}
		
		// DELETE SQL
		if (function_exists('is_multisite') && is_multisite()){
			$old_blog = $wpdb->blogid;
			$old_prefix = $wpdb->prefix ; 
			// Get all blog ids
			$blogids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM ".$wpdb->blogs));
			foreach ($blogids as $blog_id) {
				switch_to_blog($blog_id);
				$wpdb->query("DROP TABLE ".str_replace($old_prefix, $wpdb->prefix, $wpdb->prefix . "pluginSL_" . 'listplugins')) ; 
			}
			switch_to_blog($old_blog);
		} else {
			$wpdb->query("DROP TABLE ".$wpdb->prefix . "pluginSL_" . 'listplugins' ) ; 
		}
		
		// DELETE FILES if needed
		$plugin = listplugins::getInstance() ; 
		SLFramework_Utils::rm_rec(WP_CONTENT_DIR."/sedlex/".$plugin->get_param('path')."/"); 
		$plugins_all = 	get_plugins() ; 
		$nb_SL = 0 ; 	
		foreach($plugins_all as $url => $pa) {
			$info = pluginSedlex::get_plugins_data(WP_PLUGIN_DIR."/".$url);
			if ($info['Framework_Email']=="sedlex@sedlex.fr"){
				$nb_SL++ ; 
			}
		}
		if ($nb_SL==1) {
			SLFramework_Utils::rm_rec(WP_CONTENT_DIR."/sedlex/"); 
		}
		
	}

	/** ====================================================================================================================================================
	* Define the default option value of the plugin
	* 
	* @return variant of the option
	*/
	function get_default_option($option) {
		switch ($option) {
			case 'path' 		: return "sedlex_plugins" 	; break ; 
			case 'show_wordpress' : return true 	; break ; 
			case 'show_hosted' : return true 	; break ; 
			case 'show_inactive_wordpress' : return false 	; break ; 
			case 'show_inactive_hosted' : return false 	; break ; 
			case 'only_sedlex' 	: return "" 	; break ; 
			case 'html' : return "*<div class='listplugin'>
   <h4 class='listplugin_title'>%name% 
      <span class='listplugin_version'>(%version%)</span>
   </h4>
   <div class='listplugin_author'>by %author%</div>
   <div  class='listplugin_download'>%download%</div>
   <div class='listplugin_text'>%description% </div>
   <div class='listplugin_images'>
      %screen1%
      %screen2%
      %screen3%
   </div>
</div>" 	; break ; 
			case 'css' : return "*.test {}" 	; break ; 
			case 'image_size' : return 150 ; break ; 
			
		}
		return null ;
	}

	/** ====================================================================================================================================================
	* Init css for the public side
	* If you want to load a style sheet, please type :
	*	<code>$this->add_inline_css($css_text);</code>
	*	<code>$this->add_css($css_url_file);</code>
	*
	* @return void
	*/
	
	function _public_css_load() {	
		$css = $this->get_param('css') ; 
		$this->add_inline_css($css) ; 
	}
	
	/** ====================================================================================================================================================
	* The configuration page
	* 
	* @return void
	*/
	function configuration_page() {
		global $wpdb;
	
		?>
		<div class="plugin-titleSL">
			<h2><?php echo $this->pluginName ?></h2>
		</div>
		
		<div class="plugin-contentSL">		
			<?php echo $this->signature ; ?>

		<?php
			// On verifie que les droits sont corrects
			$this->check_folder_rights( array() ) ; 
			
			//==========================================================================================
			//
			// Mise en place du systeme d'onglet
			//		(bien mettre a jour les liens contenu dans les <li> qui suivent)
			//
			//==========================================================================================
			
			$tabs = new SLFramework_Tabs() ; 
			
			// HOW To
			ob_start() ;
				echo "<p>".__('This plugin generates and display the list of the plugins on your wordpress installation. Moreover it enables the download of the plugins.', $this->pluginID)."</p>" ; 
			$howto1 = new SLFramework_Box (__("Purpose of that plugin", $this->pluginID), ob_get_clean()) ; 
			ob_start() ;
				echo "<p>".sprintf(__('To display the list please type %s on pages/posts where you want the list to be displayed.', $this->pluginID), "<code>[list_plugins]</code>")."</p>" ; 
			$howto2 = new SLFramework_Box (__("How to display the list of the plugin?", $this->pluginID), ob_get_clean()) ; 
			ob_start() ; 
				 echo $howto1->flush() ; 
				 echo $howto2->flush() ; 
			$tabs->add_tab(__('How To',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_how.png") ; 	

			ob_start() ; 
				$params = new SLFramework_Parameters($this, 'tab-parameters') ; 
				
				$params->add_title(__('Which plugins do you want to list?',$this->pluginID)) ; 
				$params->add_param('show_wordpress', __('Display plugins that are hosted by Wordpress:',$this->pluginID), "", "", array('show_inactive_wordpress')) ; 
				$params->add_param('show_inactive_wordpress', __('Display Wordpress plugins that are inactive:',$this->pluginID)) ; 
				$params->add_param('show_hosted', __('Display plugins that are not hosted by Wordpress:',$this->pluginID), "", "", array('show_inactive_hosted')) ; 
				$params->add_param('show_inactive_hosted', __('Display non-Wordpress plugins that are inactive:',$this->pluginID)) ; 
				$params->add_param('only_sedlex', __('Show only plugins developped by the author:',$this->pluginID)) ; 
				$params->add_comment(__('If this field is empty, all plugins (matching the previous conditions) will be displayed',$this->pluginID)) ; 
				
				$params->add_title(__('Advanced options',$this->pluginID)) ; 
				$params->add_param('image_size', __('What is the width of screenshots (in pixels):',$this->pluginID)) ; 
				$params->add_param('html', __('What is the HTML:',$this->pluginID)) ; 
				$html = "<br><code>" ; 
				$html .= "&lt;div class='listplugin'&gt;<br>
&nbsp;&nbsp;&nbsp;&lt;h4&nbsp;class='listplugin_title'&gt;%name%&nbsp;<br>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&lt;span&nbsp;class='listplugin_version'&gt;(%version%)&lt;/span&gt;<br>
&nbsp;&nbsp;&nbsp;&lt;/h4&gt;<br>
&nbsp;&nbsp;&nbsp;&lt;div&nbsp;class='listplugin_author'&gt;by&nbsp;%author%&lt;/div&gt;<br>
&nbsp;&nbsp;&nbsp;&lt;div&nbsp;&nbsp;class='listplugin_download'&gt;%download%&lt;/div&gt;<br>
&nbsp;&nbsp;&nbsp;&lt;div&nbsp;class='listplugin_text'&gt;%description%&nbsp;&lt;/div&gt;<br>
&nbsp;&nbsp;&nbsp;&lt;div&nbsp;class='listplugin_images'&gt;<br>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;%screen1%<br>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;%screen2%<br>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;%screen3%<br>
&nbsp;&nbsp;&nbsp;&lt;/div&gt;<br>
&lt;/div&gt;<br>" ; 
				$html .= "</code>" ; 
				$params->add_comment(sprintf(__('Default HTML: %s',$this->pluginID), $html)) ; 
				$params->add_param('css', __('What is the CSS:',$this->pluginID)) ; 
				$css = "<br><code>" ; 
				$css .= ".listplugin&nbsp;{<br>
&nbsp;&nbsp;&nbsp;border:&nbsp;1px&nbsp;solid&nbsp;#666666&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;padding:&nbsp;10px&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;margin:&nbsp;10px&nbsp;;&nbsp;<br>
}<br>
<br>
.listplugin_title&nbsp;{<br>
&nbsp;&nbsp;&nbsp;font-variant:&nbsp;small-caps&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;font-size:14px&nbsp;;&nbsp;<br>
}<br>
<br>
.listplugin_version&nbsp;{<br>
&nbsp;&nbsp;&nbsp;font-variant:&nbsp;small-caps&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;font-size:10px&nbsp;;&nbsp;<br>
}<br>
<br>
.listplugin_author&nbsp;{<br>
&nbsp;&nbsp;&nbsp;font-variant:&nbsp;small-caps&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;font-size:10px&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;color:#888888&nbsp;;&nbsp;<br>
}<br>
<br>
.listplugin_text&nbsp;{<br>
&nbsp;&nbsp;&nbsp;color:#888888&nbsp;;&nbsp;<br>
}<br>
<br>
.listplugin_image&nbsp;{<br>
&nbsp;&nbsp;&nbsp;text-align:center&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;border:&nbsp;1px&nbsp;solid&nbsp;#DDDDDD&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;padding:&nbsp;10px&nbsp;;&nbsp;<br>
&nbsp;&nbsp;&nbsp;margin:&nbsp;10px&nbsp;;&nbsp;<br>
}" ; 
				$css .= "</code>" ; 
				$params->add_comment(sprintf(__('Default CSS: %s',$this->pluginID), $css)) ; 
				$params->add_param('path', __('Path to store the zip file if needed:',$this->pluginID), "@[^a-zA-Z_]@") ; 
				$params->add_comment(__('Note that, if the plugin is also hosted by wordpress.org, the download link will be a link to the plugin page on wordpress.org',$this->pluginID)) ; 
				//on verifie que le path existe et sinon on le cree
				$path = WP_CONTENT_DIR."/sedlex/".$this->get_param('path') ; 
				if (is_dir($path)) {
					$params->add_comment(sprintf(__('The path %s exists',$this->pluginID)," '<code>$path</code>' ")) ; 
				} else {
					// On cree le chemin
					if (!mkdir("$path", 0700, true)) {
						$params->add_comment(sprintf(__('The path %s does not exist and cannot be created due to rights in the folder',$this->pluginID)," '<code>$path</code>' ")) ; 
					} else {
						$params->add_comment(sprintf(__('The path %s have been just created !',$this->pluginID)," '<code>$path</code>' ")) ; 
					}
				}
				$params->flush() ; 
			$tabs->add_tab(__('Parameters',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_param.png") ; 	
			
			ob_start() ; 
				$plugin = str_replace("/","",str_replace(basename(__FILE__),"",plugin_basename( __FILE__))) ; 
				$trans = new SLFramework_Translation($this->pluginID, $plugin) ; 
				$trans->enable_translation() ; 
			$tabs->add_tab(__('Manage translations',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_trad.png") ; 	

			ob_start() ; 
				$plugin = str_replace("/","",str_replace(basename(__FILE__),"",plugin_basename( __FILE__))) ; 
				$trans = new SLFramework_Feedback($plugin, $this->pluginID) ; 
				$trans->enable_feedback() ; 
			$tabs->add_tab(__('Give feedback',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_mail.png") ; 	

			ob_start() ; 
				$trans = new SLFramework_OtherPlugins("sedLex", array('wp-pirates-search')) ; 
				$trans->list_plugins() ; 
			$tabs->add_tab(__('Other plugins',  $this->pluginID), ob_get_clean() , plugin_dir_url("/").'/'.str_replace(basename(__FILE__),"",plugin_basename(__FILE__))."core/img/tab_plug.png") ; 	
			
			echo $tabs->flush() ; 
			
			echo $this->signature ; ?>
		</div>
		<?php
	}

	/** ====================================================================================================================================================
	* Call when meet "[list_plugins]" in an article
	* 
	* @return string the replacement string
	*/	
	function list_plugins($attribs) {	
	
		$plugins_all = 	get_plugins() ; 	
		$plugins_to_show = array() ; 
		
		foreach($plugins_all as $url => $pa) {
			if ((strlen($this->get_param('only_sedlex'))==0)||(preg_match("/".$this->get_param('only_sedlex')."/i",$pa['Author']))) {
				if (preg_match("@wordpress\.org\/plugins@",$pa['PluginURI'])) {
					if ($this->get_param('show_wordpress')) {
						if (($this->get_param('show_inactive_wordpress')) || (!$this->get_param('show_inactive_wordpress') && is_plugin_active($url)) ) {
							$plugins_to_show[$url] = $pa ; 
						} 
					}
				} else {
					if ($this->get_param('show_hosted')) {
						if (($this->get_param('show_inactive_hosted')) || (!$this->get_param('show_inactive_hosted') && is_plugin_active($url)) ) {
							$plugins_to_show[$url] = $pa ; 
						} 
					}
				}
			}
		}
		
		
		// On affiche la liste des plugins
		//--
		$all_html = "" ; 
		foreach($plugins_to_show as $url_plug => $pts) {
			$html = $this->get_param('html') ;
			$html = str_replace('%name%', $pts['Name'], $html)  ; 
			$html = str_replace('%version%', $pts['Version'], $html) ;  
			$html = str_replace('%description%', str_replace("[","&#91;",$pts['Description']), $html) ; 
			$html = str_replace('%author%', $pts['Author'], $html) ; 
			
			// The download link
			$download_link = "" ; 
			// If the URI plugin point out on wordpress.org, then we put the wordpress link to the zip file.
			if (preg_match("@wordpress\.org\/plugins@",$pts['PluginURI'])) {
				$url = trim($pts['PluginURI']) ; 
				$download_link .= '<p class="download"><img src="'.plugin_dir_url("/")."/".str_replace(basename( __FILE__),"",plugin_basename(__FILE__)).'/img/zip.jpg">'; 
				$download_link .= '<a href="'.$url.'" alt="'.sprintf(__('Download %s',$this->pluginID),' '.$pts['Name']).'">' ; 
				$download_link .= sprintf(__('Download %s (on Wordpress.org)',$this->pluginID),' '.$pts['Name']).' </a></p>'; 
			} else {
				$name_zip = SLFramework_Utils::create_identifier($pts['Name'].' ').$pts['Version'].".zip" ; 
				$url = content_url()."/sedlex/".$this->get_param('path').'/'.$name_zip ; 
				$path = WP_CONTENT_DIR."/sedlex/".$this->get_param('path').'/'.$name_zip ; 
				// on cree le zip, s'il n'existe pas
				if (!is_file($path)) {
					$zip = new PclZip($path) ; 
					$dir = explode("/", $url_plug) ; 
					$result = $zip->create(WP_PLUGIN_DIR . "/". $dir[0], PCLZIP_OPT_REMOVE_PATH, WP_PLUGIN_DIR) ; 
					if ($result == 0) {
						$download_link .= sprintf(__("Error: %s", $this->pluginID), $zip->errorInfo(true));
					}
				}
				if (is_file($path)) {
					$download_link .= '<p class="download"><img src="'.plugin_dir_url("/")."/".str_replace(basename( __FILE__),"",plugin_basename(__FILE__)).'/img/zip.jpg">' ; 
					$download_link .= '<a href="'.$url.'" alt="'.sprintf(__('Download %s',$this->pluginID),' '.$pts['Name']).'">' ; 
					$download_link .= sprintf(__('Download %s',$this->pluginID),' '.$pts['Name']).'</a></p>'; 
				}
			}
			$html = str_replace('%download%', $download_link, $html) ; 
			
			// Screen Shots
			$dir = explode("/", $url_plug) ; 
			$d = @scandir(WP_PLUGIN_DIR."/".$dir[0]); //Open Directory
			if (is_array($d)) {
				foreach ($d as $file) {
					if (preg_match("/screenshot-([0-9]*)\.(jpg|jpeg|png|gif)/i", $file, $match)) {
						$url = plugin_dir_url("/")."/".$dir[0]."/".$match[0] ; 
						$screen_link = "" ; 
						$screen_link .= '<div class="listplugin_image">' ; 
						$screen_link .= '<a style="text-decoration:none;" href="'.$url.'">' ; 
						$screen_link .= '<img alt="'.$match[0].'" src="'.$url.'" style="max-width: '.$this->get_param('image_size').'px">' ; 
						$screen_link .= '</a>' ; 
						$screen_link .= '</div>' ; 
						$html = str_replace('%screen'.$match[1].'%', $screen_link, $html) ; 
					}
				}
			}
			
			for ($i=1; $i<10 ; $i++) {
				$html = str_replace('%screen'.$i.'%', "", $html) ; 
			}

			$all_html .= $html ; 
		}

		return $all_html;
	}
}

$listplugins = listplugins::getInstance();

?>
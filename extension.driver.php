<?php
	Class extension_translationmanager extends Extension{
	
		public function about(){
			return array('name' => 'Translation Manager',
						 'version' => '0.9',
						 'release-date' => '2008-12-24',
						 'author' => array('name' => 'Marcin Konicki',
										   'website' => 'http://ahwayakchih.neoni.net',
										   'email' => 'ahwayakchih@neoni.net'),
						 'description' => 'Import and export translation files, check state of translations.'
			);
		}

		public function fetchNavigation() {
			return array(
				array(
					'location'	=> 200,
					'name'		=> 'Translations',
					//'link'		=> '/list/',
					'limit'		=> 'developer',
				)
			);
		}
	}
?>
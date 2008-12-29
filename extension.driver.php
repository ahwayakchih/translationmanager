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

		// Experimental (and probably temporary) solution for translating navigation and buttons
		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/administration/',
					'delegate' => 'NavigationPreRender',
					'callback' => '__translateNavigation'
				),
			);
		}

		public function __translateNavigation($ctx) {
			if (!is_array($ctx['navigation'])) return;

			for ($i = 0; $i < count($ctx['navigation']); $i++) {
				$ctx['navigation'][$i]['name'] = @__($ctx['navigation'][$i]['name']);
				if (is_array($ctx['navigation'][$i]['children'])) {
					for ($c = 0; $c < count($ctx['navigation'][$i]['children']); $c++) {
						$ctx['navigation'][$i]['children'][$c]['name'] = @__($ctx['navigation'][$i]['children'][$c]['name']);
					}
				}
			}
		}
	}
?>
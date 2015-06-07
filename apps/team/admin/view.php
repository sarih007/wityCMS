<?php
/**
 * Team Application - Admin View
 */

defined('WITYCMS_VERSION') or die('Access denied');

/**
 * TeamAdminView is the Admin View of the Team Application.
 * 
 * @package Apps\Team\Admin
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @author Julien Blatecky <julien.blatecky@creatiwity.net>
 * @version 0.5.0-dev-07-06-2015
 */
class TeamAdminView extends WView {
	public function members(array $model) {
		$this->assign('require', 'witycms/admin');
		$this->assign('members', $model['members']);
	}
	
	private function memberForm(array $model) {
		$this->assign('js', '/libraries/ckeditor-4.4.7/ckeditor.js');
		$this->assign('require', 'witycms/admin');
		
		$default = array(
			'id'          => '',
			'name'        => '',
			'description' => '',
			'email'       => '',
			'linkedin'    => '',
			'twitter'     => '',
			'image'       => '',
		);
		$default_translatable = array(
			'title'       => '',
		);
		$lang_list = array(1, 2);
		
		foreach ($default_translatable as $key => $value) {
			foreach ($lang_list as $id_lang) {
				$default[$key.'_'.$id_lang] = $value;
			}
		}
		
		$this->assignDefault($default, $model['data']);
		
		$this->setTemplate('member-form');
	}
	
	public function memberAdd(array $model) {
		$this->memberForm($model);
	}
	
	public function memberEdit(array $model) {
		$this->memberForm($model);
	}
	
	public function memberDelete(array $model) {
		$this->assign('name', $model['name']);
		$this->assign('confirm_delete_url', '/admin/team/member-delete/'.$model['id'].'/confirm');
	}
}

?>

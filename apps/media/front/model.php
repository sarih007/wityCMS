<?php
/**
 * Media Application - Front Model - /apps/media/front/model.php
 */

defined('WITYCMS_VERSION') or die('Access denied');

/**
 * MediaModel is the Front Model of the Media Application
 *
 * @package Apps
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @author Julien Blatecky <julien.blatecky@creatiwity.net>
 * @version 0.3-19-04-2013
 */
class MediaModel {
	protected $db;

	public function __construct() {
		$this->db = WSystem::getDB();

		// Declare table
		$this->db->declareTable('media');
	}
}

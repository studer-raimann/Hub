<?php
require_once('./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/Hub/classes/class.srModelObjectRepositoryObject.php');
require_once('./Modules/Category/classes/class.ilObjCategory.php');
require_once('./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/Hub/classes/Category/class.hubCategoryFields.php');

/**
 * Class hubCategory
 *
 *
 * @author  Fabian Schmid <fs@studer-raimann.ch>
 * @version 1.0.0
 *
 * @revision $r$
 */
class hubCategory extends srModelObjectRepositoryObject {

	/**
	 * @var ilObjCategory
	 */
	public $ilias_object;


	/**
	 * @return string
	 */
	static function returnDbTableName() {
		return 'sr_hub_category';
	}


	/**
	 * @return bool|mixed
	 */
	public static function buildILIASObjects() {
		/**
		 * @var $hubOrigin hubOrigin
		 */
		foreach (hubOrigin::getOriginsForUsage(hub::OBJECTTYPE_CATEGORY) as $hubOrigin) {
			self::buildForParentId($hubOrigin->props()->get(hubCategoryFields::BASE_NODE_EXTERNAL));
		}

		return true;
	}


	/**
	 * @param $parent_id
	 */
	private static function buildForParentId($parent_id = 0) {
		/**
		 * @var $hubCategory hubCategory
		 */
		hubCounter::logRunning();
		foreach (self::where(array( 'parent_id' => $parent_id ))->get() as $hubCategory) {
			if (! hubSyncHistory::isLoaded($hubCategory->getSrHubOriginId())) {
				continue;
			}
			$hubCategory->loadObjectProperties();
			$existing_ref_id = 0;
			switch ($hubCategory->props()->get(hubCategoryFields::SYNCFIELD)) {
				case 'title':
					$existing_ref_id = $hubCategory->lookupRefIdByTitle();
					break;
			}
			if ($existing_ref_id > 1 AND $hubCategory->getHistoryObject()->getStatus() == hubSyncHistory::STATUS_NEW) {
				$history = $hubCategory->getHistoryObject();
				$history->setIliasId($existing_ref_id);
				$history->setIliasIdType(self::ILIAS_ID_TYPE_USER);
				$history->update();
			}
			$hubCategory->loadObjectProperties();
			switch ($hubCategory->getHistoryObject()->getStatus()) {
				case hubSyncHistory::STATUS_NEW:
					$hubCategory->createCategory();
					hubCounter::incrementCreated($hubCategory->getSrHubOriginId());
					hubOriginNotification::addMessage($hubCategory->getSrHubOriginId(), $hubCategory->getTitle(), 'Category created:');
					break;
				case hubSyncHistory::STATUS_UPDATED:
					$hubCategory->updateCategory();
					hubCounter::incrementUpdated($hubCategory->getSrHubOriginId());
					//					hubOriginNotification::addMessage($hubCategory->getSrHubOriginId(), $hubCategory->getTitle(), 'Category updated:');
					break;
				case hubSyncHistory::STATUS_DELETED:
					$hubCategory->deleteCategory();
					hubCounter::incrementDeleted($hubCategory->getSrHubOriginId());
					hubOriginNotification::addMessage($hubCategory->getSrHubOriginId(), $hubCategory->getTitle(), 'Category deleted:');
					break;
				case hubSyncHistory::STATUS_ALREADY_DELETED:
					hubCounter::incrementIgnored($hubCategory->getSrHubOriginId());
					hubOriginNotification::addMessage($hubCategory->getSrHubOriginId(), $hubCategory->getTitle(), 'Category ignored:');
					break;
				case hubSyncHistory::STATUS_NEWLY_DELIVERED:
					hubCounter::incrementNewlyDelivered($hubCategory->getSrHubOriginId());
					hubOriginNotification::addMessage($hubCategory->getSrHubOriginId(), $hubCategory->getTitle(), 'Category newly delivered:');
					$hubCategory->updateCategory();
					break;
			}
			$hubCategory->getHistoryObject()->updatePickupDate();
			$hubOrigin = hubOrigin::getClassnameForOriginId($hubCategory->getSrHubOriginId());
			$hubOrigin::afterObjectModification($hubCategory);
			if ($hubCategory->getExtId() !== 0 AND $hubCategory->getExtId() !== NULL AND $hubCategory->getExtId() !== ''
			) {
				self::buildForParentId($hubCategory->getExtId());
			}
		}
	}


	/**
	 * @return int
	 */
	protected function lookupRefIdByTitle() {
		return $this->lookupRefIdByField('title', $this->getTitle());
	}


	/**
	 * @param $fieldname
	 * @param $value
	 *
	 * @return int
	 */
	protected function lookupRefIdByField($fieldname, $value) {
		global $tree;
		/**
		 * @var $tree
		 */
		$node = $this->getNode();
		foreach ($tree->getChildsByType($node, 'cat') as $cat) {
			if ($cat[$fieldname] == $value) {
				return $cat['ref_id'];
			}
		}

		return 0;
	}


	protected function updateCategory() {
		$update = false;
		if ($this->props()->get(hubCategoryFields::UPDATE_TITLE)) {
			$this->initObject();
			$this->ilias_object->setTitle($this->getTitle());
			$update = true;
		}
		if ($this->props()->get(hubCategoryFields::UPDATE_DESCRIPTION)) {
			$this->initObject();
			$this->ilias_object->setDescription($this->getDescription());
			$update = true;
		}
		if ($this->props()->get(hubCategoryFields::UPDATE_ICON)) {
			$this->initObject();
			$this->updateIcon();
		}
		if ($this->props()->get(hubCategoryFields::MOVE)) {
			$this->initObject();
			global $tree, $rbacadmin;
			$ref_id = $this->ilias_object->getRefId();
			$old_parent = $tree->getParentId($ref_id);
			$tree->moveTree($ref_id, $this->getNode());
			$rbacadmin->adjustMovedObjectPermissions($ref_id, $old_parent);
			$update = true;
		}
		if ($update) {
			$this->ilias_object->setImportId($this->returnImportId());
			$this->ilias_object->update();
		}
	}


	protected function deleteCategory() {
		if ($this->props()->get(hubCategoryFields::DELETE)) {
			$hist = $this->getHistoryObject();
			$this->ilias_object = new ilObjCategory($this->getHistoryObject()->getIliasId());
			switch ($this->props()->get(hubCategoryFields::DELETE)) {
				case self::DELETE_MODE_INACTIVE:
					$this->ilias_object->setTitle($this->getTitle() . ' '
						. $this->pl->txt('com_prop_mark_deleted_text'));
					if ($this->props()->get(hubCategoryFields::DELETED_ICON)) {
						$icon = $this->props()->getIconPath('_deleted');
						if ($icon) {
							$this->ilias_object->saveIcons($icon, $icon, $icon);
						}
					}

					$this->ilias_object->update();
					break;
				case self::DELETE_MODE_DELETE:
					$this->ilias_object->delete();
					$hist->setIliasId(NULL);
					break;
				case self::DELETE_MODE_ARCHIVE:
					if ($this->props()->get(hubCategoryFields::ARCHIVE_NODE)) {
						global $tree, $rbacadmin;
						$ref_id = $this->ilias_object->getRefId();
						$old_parent = $tree->getParentId($ref_id);
						$tree->moveTree($ref_id, $this->props()->get(hubCategoryFields::ARCHIVE_NODE));
						$rbacadmin->adjustMovedObjectPermissions($ref_id, $old_parent);
					}
					break;
			}
			$hist->setDeleted(true);
			$hist->setAlreadyDeleted(true);
			$hist->update();
		}
	}


	private function createCategory() {
		$this->ilias_object = new ilObjCategory();
		$this->ilias_object->setTitle($this->getTitle());
		$this->ilias_object->setDescription($this->getDescription());
		$this->ilias_object->setImportId($this->returnImportId());
		$this->ilias_object->setOwner(6);
		$this->ilias_object->create();
		$this->ilias_object->createReference();
		$node = $this->getNode();
		$this->ilias_object->putInTree($node);
		$this->ilias_object->setPermissions($node);
		if ($this->props()->get(hubCategoryFields::CREATE_ICON)) {
			$this->updateIcon();
		}
		$history = $this->getHistoryObject();
		$history->setIliasId($this->ilias_object->getRefId());
		$history->setIliasIdType(self::ILIAS_ID_TYPE_REF_ID);
		$history->update();
	}


	/**
	 * @return int
	 */
	public function getNode() {
		/**
		 * @var $tree ilTree
		 */
		global $tree;
		$base_node_prop = $this->props()->get(hubCategoryFields::BASE_NODE_ILIAS);
		$base_node_ilias = ($base_node_prop ? $base_node_prop : 1);
		if ($this->getParentIdType() == self::PARENT_ID_TYPE_EXTERNAL_ID) {
			if ($this->getExtId() == $this->props()->get(hubCategoryFields::BASE_NODE_EXTERNAL)) {
				return $base_node_ilias;
			} else {
				$parent_id = ilObject::_getAllReferences(ilObject::_lookupObjIdByImportId($this->returnParentImportId()));
				$keys = array_keys($parent_id);
				$node = $keys [0];
				if ($node) {
					return $node;
				} else {
					return $base_node_ilias;
				}
			}
		} elseif ($this->getParentIdType() == self::PARENT_ID_TYPE_REF_ID) {
			if (! $tree->isInTree($this->getParentId())) {
				return $base_node_ilias;
			} else {
				return $this->getParentId();
			}
		}
	}


	protected function initObject() {
		if (! isset($this->ilias_object)) {
			$this->ilias_object = ilObjectFactory::getInstanceByRefId($this->getHistoryObject()->getIliasId());
		}
	}
}

?>
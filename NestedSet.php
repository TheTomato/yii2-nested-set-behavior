<?php
/**
 * @link https://github.com/creocoder/yii2-nested-set-behavior
 * @copyright Copyright (c) 2013 Alexander Kochetov
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace creocoder\behaviors;

use yii\base\Behavior;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Exception;

/**
 * @author Alexander Kochetov <creocoder@gmail.com>
 */
class NestedSet extends Behavior
{
	/**
	 * @var ActiveRecord the owner of this behavior.
	 */
	public $owner;
	/**
	 * @var bool
	 */
	public $hasManyRoots = false;
	/**
	 * @var string
	 */
	public $leftAttribute = 'lft';
	/**
	 * @var string
	 */
	public $rightAttribute = 'rgt';
	/**
	 * @var string
	 */
	public $levelAttribute = 'level';
	/**
	 * @var bool
	 */
	private $_ignoreEvent = false;
	/**
	 * @var bool
	 */
	private $_deleted = false;
	/**
	 * @var int
	 */
	private $_id;
	/**
	 * @var array
	 */
	private static $_cached;
	/**
	 * @var int
	 */
	private static $_c = 0;

	/**
	 * @inheritdoc
	 */
	public function events()
	{
		return [
			ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
			ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
			ActiveRecord::EVENT_BEFORE_INSERT => 'beforeInsert',
			ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeUpdate',
		];
	}

	/**
	 * @inheritdoc
	 */
	public function attach($owner)
	{
		parent::attach($owner);
		self::$_cached[get_class($this->owner)][$this->_id = self::$_c++] = $this->owner;
	}

	/**
	 * Gets descendants for node.
	 * @param int $depth the depth.
	 * @return ActiveQuery.
	 */
	public function descendants($depth = null)
	{
		$query = $this->owner->find();
		$db = $this->owner->getDb();
		$query->andWhere($db->quoteColumnName($this->leftAttribute) . '>'
			. $this->owner->getAttribute($this->leftAttribute));
		$query->andWhere($db->quoteColumnName($this->rightAttribute) . '<'
			. $this->owner->getAttribute($this->rightAttribute));
		$query->addOrderBy($db->quoteColumnName($this->leftAttribute));

		if ($depth !== null) {
			$query->andWhere($db->quoteColumnName($this->levelAttribute) . '<='
				. ($this->owner->getAttribute($this->levelAttribute) + $depth));
		}

		return $query;
	}

	/**
	 * Gets children for node (direct descendants only).
	 * @return ActiveQuery.
	 */
	public function children()
	{
		return $this->descendants(1);
	}

	/**
	 * Gets ancestors for node.
	 * @param int $depth the depth.
	 * @return ActiveQuery.
	 */
	public function ancestors($depth = null)
	{
		$query = $this->owner->find();
		$db = $this->owner->getDb();
		$query->andWhere($db->quoteColumnName($this->leftAttribute) . '<'
			. $this->owner->getAttribute($this->leftAttribute));
		$query->andWhere($db->quoteColumnName($this->rightAttribute) . '>'
			. $this->owner->getAttribute($this->rightAttribute));
		$query->addOrderBy($db->quoteColumnName($this->leftAttribute));

		if ($depth !== null) {
			$query->andWhere($db->quoteColumnName($this->levelAttribute) . '>='
				. ($this->owner->getAttribute($this->levelAttribute) - $depth));
		}

		return $query;
	}

	/**
	 * Gets parent of node.
	 * @return ActiveQuery.
	 */
	public function parent()
	{
		$query = $this->owner->find();
		$db = $this->owner->getDb();
		$query->andWhere($db->quoteColumnName($this->leftAttribute) . '<'
			. $this->owner->getAttribute($this->leftAttribute));
		$query->andWhere($db->quoteColumnName($this->rightAttribute) . '>'
			. $this->owner->getAttribute($this->rightAttribute));
		$query->addOrderBy($db->quoteColumnName($this->rightAttribute));

		return $query;
	}

	/**
	 * Gets previous sibling of node.
	 * @return ActiveQuery.
	 */
	public function prev()
	{
		$query = $this->owner->find();
		$db = $this->owner->getDb();
		$query->andWhere($db->quoteColumnName($this->rightAttribute) . '='
			. ($this->owner->getAttribute($this->leftAttribute) - 1));

		return $query;
	}

	/**
	 * Gets next sibling of node.
	 * @return ActiveQuery.
	 */
	public function next()
	{
		$query = $this->owner->find();
		$db = $this->owner->getDb();
		$query->andWhere($db->quoteColumnName($this->leftAttribute) . '='
			. ($this->owner->getAttribute($this->rightAttribute) + 1));

		return $query;
	}

	/**
	 * Create root node if multiple-root tree mode. Update node if it's not new.
	 * @param boolean $runValidation whether to perform validation.
	 * @param array $attributes list of attributes.
	 * @return boolean whether the saving succeeds.
	 */
	public function save($runValidation = true, $attributes = null)
	{
		if ($runValidation && !$this->owner->validate($attributes)) {
			return false;
		}

		if ($this->owner->getIsNewRecord()) {
			return $this->makeRoot($attributes);
		}

		$this->_ignoreEvent = true;
		$result = $this->owner->update(false, $attributes);
		$this->_ignoreEvent = false;

		return $result;
	}

	/**
	 * Create root node if multiple-root tree mode. Update node if it's not new.
	 * @param boolean $runValidation whether to perform validation.
	 * @param array $attributes list of attributes.
	 * @return boolean whether the saving succeeds.
	 */
	public function saveNode($runValidation = true, $attributes = null)
	{
		return $this->save($runValidation, $attributes);
	}

	/**
	 * Deletes node and it's descendants.
	 * @throws Exception.
	 * @throws \Exception.
	 * @return boolean whether the deletion is successful.
	 */
	public function delete()
	{
		if ($this->owner->getIsNewRecord()) {
			throw new Exception('The node can\'t be deleted because it is new.');
		}

		if ($this->getIsDeletedRecord()) {
			throw new Exception('The node can\'t be deleted because it is already deleted.');
		}

		$db = $this->owner->getDb();

		if ($db->getTransaction() === null) {
			$transaction = $db->beginTransaction();
		}

		try {
			if ($this->owner->isLeaf()) {
				$this->_ignoreEvent = true;
				$result = $this->owner->delete();
				$this->_ignoreEvent = false;
			} else {
				$condition = $db->quoteColumnName($this->leftAttribute) . '>='
					. $this->owner->getAttribute($this->leftAttribute) . ' AND '
					. $db->quoteColumnName($this->rightAttribute) . '<='
					. $this->owner->getAttribute($this->rightAttribute);
				$params = [];

				$result = $this->owner->deleteAll($condition, $params) > 0;
			}

			if (!$result) {
				if (isset($transaction)) {
					$transaction->rollback();
				}

				return false;
			}

			$this->shiftLeftRight(
				$this->owner->getAttribute($this->rightAttribute) + 1,
				$this->owner->getAttribute($this->leftAttribute) - $this->owner->getAttribute($this->rightAttribute) - 1
			);

			if (isset($transaction)) {
				$transaction->commit();
			}

			$this->correctCachedOnDelete();
		} catch (\Exception $e) {
			if (isset($transaction)) {
				$transaction->rollback();
			}

			throw $e;
		}

		return true;
	}

	/**
	 * Deletes node and it's descendants.
	 * @return boolean whether the deletion is successful.
	 */
	public function deleteNode()
	{
		return $this->delete();
	}

	/**
	 * Prepends node to target as first child.
	 * @param ActiveRecord $target the target.
	 * @param boolean $runValidation whether to perform validation.
	 * @param array $attributes list of attributes.
	 * @return boolean whether the prepending succeeds.
	 */
	public function prependTo($target, $runValidation = true, $attributes = null)
	{
		return $this->addNode(
			$target,
			$target->getAttribute($this->leftAttribute) + 1,
			1,
			$runValidation,
			$attributes
		);
	}

	/**
	 * Prepends target to node as first child.
	 * @param ActiveRecord $target the target.
	 * @param boolean $runValidation whether to perform validation.
	 * @param array $attributes list of attributes.
	 * @return boolean whether the prepending succeeds.
	 */
	public function prepend($target, $runValidation = true, $attributes = null)
	{
		return $target->prependTo(
			$this->owner,
			$runValidation,
			$attributes
		);
	}

	/**
	 * Appends node to target as last child.
	 * @param ActiveRecord $target the target.
	 * @param boolean $runValidation whether to perform validation.
	 * @param array $attributes list of attributes.
	 * @return boolean whether the appending succeeds.
	 */
	public function appendTo($target, $runValidation = true, $attributes = null)
	{
		return $this->addNode(
			$target,
			$target->getAttribute($this->rightAttribute),
			1,
			$runValidation,
			$attributes
		);
	}

	/**
	 * Appends target to node as last child.
	 * @param ActiveRecord $target the target.
	 * @param boolean $runValidation whether to perform validation.
	 * @param array $attributes list of attributes.
	 * @return boolean whether the appending succeeds.
	 */
	public function append($target, $runValidation = true, $attributes = null)
	{
		return $target->appendTo(
			$this->owner,
			$runValidation,
			$attributes
		);
	}

	/**
	 * Inserts node as previous sibling of target.
	 * @param ActiveRecord $target the target.
	 * @param boolean $runValidation whether to perform validation.
	 * @param array $attributes list of attributes.
	 * @return boolean whether the inserting succeeds.
	 */
	public function insertBefore($target, $runValidation = true, $attributes = null)
	{
		return $this->addNode(
			$target,
			$target->getAttribute($this->leftAttribute),
			0,
			$runValidation,
			$attributes
		);
	}

	/**
	 * Inserts node as next sibling of target.
	 * @param ActiveRecord $target the target.
	 * @param boolean $runValidation whether to perform validation.
	 * @param array $attributes list of attributes.
	 * @return boolean whether the inserting succeeds.
	 */
	public function insertAfter($target, $runValidation = true, $attributes = null)
	{
		return $this->addNode(
			$target,
			$target->getAttribute($this->rightAttribute) + 1,
			0,
			$runValidation,
			$attributes
		);
	}

	/**
	 * Move node as previous sibling of target.
	 * @param ActiveRecord $target the target.
	 * @return boolean whether the moving succeeds.
	 */
	public function moveBefore($target)
	{
		return $this->moveNode(
			$target,
			$target->getAttribute($this->leftAttribute),
			0
		);
	}

	/**
	 * Move node as next sibling of target.
	 * @param ActiveRecord $target the target.
	 * @return boolean whether the moving succeeds.
	 */
	public function moveAfter($target)
	{
		return $this->moveNode(
			$target,
			$target->getAttribute($this->rightAttribute) + 1,
			0
		);
	}

	/**
	 * Move node as first child of target.
	 * @param ActiveRecord $target the target.
	 * @return boolean whether the moving succeeds.
	 */
	public function moveAsFirst($target)
	{
		return $this->moveNode(
			$target,
			$target->getAttribute($this->leftAttribute) + 1,
			1
		);
	}

	/**
	 * Move node as last child of target.
	 * @param ActiveRecord $target the target.
	 * @return boolean whether the moving succeeds.
	 */
	public function moveAsLast($target)
	{
		return $this->moveNode(
			$target,
			$target->getAttribute($this->rightAttribute),
			1
		);
	}

	/**
	 * Move node as new root.
	 * @throws Exception.
	 * @throws \Exception.
	 * @return boolean whether the moving succeeds.
	 */
	public function moveAsRoot()
	{
		if (!$this->hasManyRoots) {
			throw new Exception('Many roots mode is off.');
		}

		if ($this->owner->getIsNewRecord()) {
			throw new Exception('The node should not be new record.');
		}

		if ($this->getIsDeletedRecord()) {
			throw new Exception('The node should not be deleted.');
		}

		if ($this->owner->isRoot()) {
			throw new Exception('The node already is root node.');
		}

		$db = $this->owner->getDb();

		if ($db->getTransaction() === null) {
			$transaction = $db->beginTransaction();
		}

		$lastRoot = $this->owner->find()->orderBy($this->rightAttribute.' desc')->one();
		
		if ($lastRoot == null) {
			throw new Exception('No roots found.');
		}
		
		$this->moveAfter($lastRoot);

		return true;
	}

	/**
	 * Determines if node is descendant of subject node.
	 * @param ActiveRecord $subj the subject node.
	 * @return boolean whether the node is descendant of subject node.
	 */
	public function isDescendantOf($subj)
	{
		$result = ($this->owner->getAttribute($this->leftAttribute) > $subj->getAttribute($this->leftAttribute))
			&& ($this->owner->getAttribute($this->rightAttribute) < $subj->getAttribute($this->rightAttribute));

		return $result;
	}

	/**
	 * Determines if node is leaf.
	 * @return boolean whether the node is leaf.
	 */
	public function isLeaf()
	{
		return $this->owner->getAttribute($this->rightAttribute)
			- $this->owner->getAttribute($this->leftAttribute) === 1;
	}

	/**
	 * Determines if node is root.
	 * @return boolean whether the node is root.
	 */
	public function isRoot()
	{
		return $this->owner->getAttribute($this->levelAttribute) == 1;
	}

	/**
	 * Returns if the current node is deleted.
	 * @return boolean whether the node is deleted.
	 */
	public function getIsDeletedRecord()
	{
		return $this->_deleted;
	}

	/**
	 * Sets if the current node is deleted.
	 * @param boolean $value whether the node is deleted.
	 */
	public function setIsDeletedRecord($value)
	{
		$this->_deleted = $value;
	}

	/**
	 * Handle 'afterFind' event of the owner.
	 * @param Event $event event parameter.
	 */
	public function afterFind($event)
	{
		self::$_cached[get_class($this->owner)][$this->_id = self::$_c++] = $this->owner;
	}

	/**
	 * Handle 'beforeInsert' event of the owner.
	 * @param Event $event event parameter.
	 * @throws Exception.
	 * @return boolean.
	 */
	public function beforeInsert($event)
	{
		if ($this->_ignoreEvent) {
			return true;
		} else {
			throw new Exception('You should not use ActiveRecord::save() or ActiveRecord::insert() methods when NestedSet behavior attached.');
		}
	}

	/**
	 * Handle 'beforeUpdate' event of the owner.
	 * @param Event $event event parameter.
	 * @throws Exception.
	 * @return boolean.
	 */
	public function beforeUpdate($event)
	{
		if ($this->_ignoreEvent) {
			return true;
		} else {
			throw new Exception('You should not use ActiveRecord::save() or ActiveRecord::update() methods when NestedSet behavior attached.');
		}
	}

	/**
	 * Handle 'beforeDelete' event of the owner.
	 * @param Event $event event parameter.
	 * @throws Exception.
	 * @return boolean.
	 */
	public function beforeDelete($event)
	{
		if ($this->_ignoreEvent) {
			return true;
		} else {
			throw new Exception('You should not use ActiveRecord::delete() method when NestedSet behavior attached.');
		}
	}

	/**
	 * @param int $key.
	 * @param int $delta.
	 */
	private function shiftLeftRight($key, $delta)
	{
		$db = $this->owner->getDb();

		foreach ([$this->leftAttribute, $this->rightAttribute] as $attribute) {
			$condition = $db->quoteColumnName($attribute) . '>=' . $key;
			$params = [];

			$this->owner->updateAll(
				[$attribute => new Expression($db->quoteColumnName($attribute) . sprintf('%+d', $delta))],
				$condition,
				$params
			);
		}
	}

	/**
	 * @param ActiveRecord $target.
	 * @param int $key.
	 * @param int $levelUp.
	 * @param boolean $runValidation.
	 * @param array $attributes.
	 * @throws Exception.
	 * @throws \Exception.
	 * @return boolean.
	 */
	private function addNode($target, $key, $levelUp, $runValidation, $attributes)
	{
		if (!$this->owner->getIsNewRecord()) {
			throw new Exception('The node can\'t be inserted because it is not new.');
		}

		if ($this->getIsDeletedRecord()) {
			throw new Exception('The node can\'t be inserted because it is deleted.');
		}

		if ($target->getIsDeletedRecord()) {
			throw new Exception('The node can\'t be inserted because target node is deleted.');
		}

		if ($this->owner->equals($target)) {
			throw new Exception('The target node should not be self.');
		}

		if ($runValidation && !$this->owner->validate()) {
			return false;
		}

		$db = $this->owner->getDb();

		if ($db->getTransaction() === null) {
			$transaction = $db->beginTransaction();
		}

		try {
			$this->shiftLeftRight($key, 2);
			$this->owner->setAttribute($this->leftAttribute, $key);
			$this->owner->setAttribute($this->rightAttribute, $key + 1);
			$this->owner->setAttribute($this->levelAttribute, $target->getAttribute($this->levelAttribute) + $levelUp);
			$this->_ignoreEvent = true;
			$result = $this->owner->insert(false, $attributes);
			$this->_ignoreEvent = false;

			if (!$result) {
				if (isset($transaction)) {
					$transaction->rollback();
				}

				return false;
			}

			if (isset($transaction)) {
				$transaction->commit();
			}

			$this->correctCachedOnAddNode($key);
		} catch (\Exception $e) {
			if (isset($transaction)) {
				$transaction->rollback();
			}

			throw $e;
		}

		return true;
	}

	/**
	 * @param array $attributes.
	 * @throws Exception.
	 * @throws \Exception.
	 * @return boolean.
	 */
	private function makeRoot($attributes)
	{
		$this->owner->setAttribute($this->leftAttribute, 1);
		$this->owner->setAttribute($this->rightAttribute, 2);
		$this->owner->setAttribute($this->levelAttribute, 1);
		
		$lastRoot = $this->owner->find()->orderBy($this->rightAttribute.' desc')->one();
		
		if (!$this->hasManyRoots && $lastRoot !== null) {
			throw new Exception('Can\'t create more than one root in single root mode.');
		}

		if ($lastRoot !== null) {
			$this->owner->setAttribute($this->leftAttribute, $lastRoot->{$this->rightAttribute} + 1);
			$this->owner->setAttribute($this->rightAttribute, $lastRoot->{$this->rightAttribute} + 2);	
		} 

		$this->_ignoreEvent = true;
		$result = $this->owner->insert(false, $attributes);
		$this->_ignoreEvent = false;

		if (!$result) {
			return false;
		}

		return true;
	}

	/**
	 * @param ActiveRecord $target.
	 * @param int $key.
	 * @param int $levelUp.
	 * @throws Exception.
	 * @throws \Exception.
	 * @return boolean.
	 */
	private function moveNode($target, $key, $levelUp)
	{
		if ($this->owner->getIsNewRecord()) {
			throw new Exception('The node should not be new record.');
		}

		if ($this->getIsDeletedRecord()) {
			throw new Exception('The node should not be deleted.');
		}

		if ($target->getIsDeletedRecord()) {
			throw new Exception('The target node should not be deleted.');
		}

		if ($this->owner->equals($target)) {
			throw new Exception('The target node should not be self.');
		}

		if ($target->isDescendantOf($this->owner)) {
			throw new Exception('The target node should not be descendant.');
		}

		$db = $this->owner->getDb();

		if ($db->getTransaction() === null) {
			$transaction = $db->beginTransaction();
		}

		try {
			$left = $this->owner->getAttribute($this->leftAttribute);
			$right = $this->owner->getAttribute($this->rightAttribute);
			$levelDelta = $target->getAttribute($this->levelAttribute) - $this->owner->getAttribute($this->levelAttribute)
				+ $levelUp;

			$delta = $right - $left + 1;
			$this->shiftLeftRight($key, $delta);

			if ($left >= $key) {
				$left += $delta;
				$right += $delta;
			}

			$condition = $db->quoteColumnName($this->leftAttribute) . '>=' . $left . ' AND '
				. $db->quoteColumnName($this->rightAttribute) . '<=' . $right;
			$params = [];

			$this->owner->updateAll(
				[
					$this->levelAttribute => new Expression($db->quoteColumnName($this->levelAttribute)
						. sprintf('%+d', $levelDelta)),
				],
				$condition,
				$params
			);

			foreach ([$this->leftAttribute, $this->rightAttribute] as $attribute) {
				$condition = $db->quoteColumnName($attribute) . '>=' . $left . ' AND '
					. $db->quoteColumnName($attribute) . '<=' . $right;
				$params = [];

				$this->owner->updateAll(
					[$attribute => new Expression($db->quoteColumnName($attribute)
						. sprintf('%+d', $key - $left))],
					$condition,
					$params
				);
			}

			$this->shiftLeftRight($right + 1, -$delta);

			if (isset($transaction)) {
				$transaction->commit();
			}

			$this->correctCachedOnMoveNode($key, $levelDelta);
		} catch (\Exception $e) {
			if (isset($transaction)) {
				$transaction->rollback();
			}

			throw $e;
		}

		return true;
	}

	/**
	 * Correct cache for [[delete()]] and [[deleteNode()]].
	 */
	private function correctCachedOnDelete()
	{
		$left = $this->owner->getAttribute($this->leftAttribute);
		$right = $this->owner->getAttribute($this->rightAttribute);
		$key = $right + 1;
		$delta = $left - $right - 1;

		foreach (self::$_cached[get_class($this->owner)] as $node) {
			/** @var $node ActiveRecord */
			if ($node->getIsNewRecord() || $node->getIsDeletedRecord()) {
				continue;
			}

			if ($node->getAttribute($this->leftAttribute) >= $left
				&& $node->getAttribute($this->rightAttribute) <= $right) {
				$node->setIsDeletedRecord(true);
			} else {
				if ($node->getAttribute($this->leftAttribute) >= $key) {
					$node->setAttribute(
						$this->leftAttribute,
						$node->getAttribute($this->leftAttribute) + $delta
					);
				}

				if ($node->getAttribute($this->rightAttribute) >= $key) {
					$node->setAttribute(
						$this->rightAttribute,
						$node->getAttribute($this->rightAttribute) + $delta
					);
				}
			}
		}
	}

	/**
	 * Correct cache for [[addNode()]].
	 * @param int $key.
	 */
	private function correctCachedOnAddNode($key)
	{
		foreach (self::$_cached[get_class($this->owner)] as $node) {
			/** @var $node ActiveRecord */
			if ($node->getIsNewRecord() || $node->getIsDeletedRecord()) {
				continue;
			}

			if ($this->owner === $node) {
				continue;
			}

			if ($node->getAttribute($this->leftAttribute) >= $key) {
				$node->setAttribute(
					$this->leftAttribute,
					$node->getAttribute($this->leftAttribute) + 2
				);
			}

			if ($node->getAttribute($this->rightAttribute) >= $key) {
				$node->setAttribute(
					$this->rightAttribute,
					$node->getAttribute($this->rightAttribute) + 2
				);
			}
		}
	}

	/**
	 * Correct cache for [[moveNode()]].
	 * @param int $key.
	 * @param int $levelDelta.
	 */
	private function correctCachedOnMoveNode($key, $levelDelta)
	{
		$left = $this->owner->getAttribute($this->leftAttribute);
		$right = $this->owner->getAttribute($this->rightAttribute);
		$delta = $right - $left + 1;

		if ($left >= $key) {
			$left += $delta;
			$right += $delta;
		}

		$delta2 = $key - $left;

		foreach (self::$_cached[get_class($this->owner)] as $node) {
			/** @var $node ActiveRecord */
			if ($node->getIsNewRecord() || $node->getIsDeletedRecord()) {
				continue;
			}

			if ($node->getAttribute($this->leftAttribute) >= $key) {
				$node->setAttribute(
					$this->leftAttribute,
					$node->getAttribute($this->leftAttribute) + $delta
				);
			}

			if ($node->getAttribute($this->rightAttribute) >= $key) {
				$node->setAttribute(
					$this->rightAttribute,
					$node->getAttribute($this->rightAttribute) + $delta
				);
			}

			if ($node->getAttribute($this->leftAttribute) >= $left
				&& $node->getAttribute($this->rightAttribute) <= $right) {
				$node->setAttribute(
					$this->levelAttribute,
					$node->getAttribute($this->levelAttribute) + $levelDelta
				);
			}

			if ($node->getAttribute($this->leftAttribute) >= $left
				&& $node->getAttribute($this->leftAttribute) <= $right) {
				$node->setAttribute(
					$this->leftAttribute,
					$node->getAttribute($this->leftAttribute) + $delta2
				);
			}

			if ($node->getAttribute($this->rightAttribute) >= $left
				&& $node->getAttribute($this->rightAttribute) <= $right) {
				$node->setAttribute(
					$this->rightAttribute,
					$node->getAttribute($this->rightAttribute) + $delta2
				);
			}

			if ($node->getAttribute($this->leftAttribute) >= $right + 1) {
				$node->setAttribute(
					$this->leftAttribute,
					$node->getAttribute($this->leftAttribute) - $delta
				);
			}

			if ($node->getAttribute($this->rightAttribute) >= $right + 1) {
				$node->setAttribute(
					$this->rightAttribute,
					$node->getAttribute($this->rightAttribute) - $delta
				);
			}
		}
	}

	/**
	 * Destructor.
	 */
	public function __destruct()
	{
		unset(self::$_cached[get_class($this->owner)][$this->_id]);
	}
}

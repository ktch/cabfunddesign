<?php
namespace Craft;

/**
 * Craft by Pixel & Tonic
 *
 * @package   Craft
 * @author    Pixel & Tonic, Inc.
 * @copyright Copyright (c) 2013, Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license Craft License Agreement
 * @link      http://buildwithcraft.com
 */

/**
 * Field model class
 */
class FieldModel extends BaseComponentModel
{
	/**
	 * Use the translated field name as the string representation.
	 *
	 * @return string
	 */
	function __toString()
	{
		return Craft::t($this->name);
	}

	/**
	 * @access protected
	 * @return array
	 */
	protected function defineAttributes()
	{
		return array_merge(parent::defineAttributes(), array(
			'groupId'      => AttributeType::Number,
			'name'         => AttributeType::String,
			'handle'       => AttributeType::String,
			'instructions' => AttributeType::String,
			'required'     => AttributeType::Bool,
			'translatable' => AttributeType::Bool,
		));
	}

	/**
	 * Returns the field's group.
	 *
	 * @return EntryUserModel
	 */
	public function getGroup()
	{
		return craft()->fields->getGroupById($this->groupId);
	}
}

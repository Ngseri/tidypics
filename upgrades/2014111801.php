<?php
use Elgg\Database\QueryBuilder;
use Elgg\Database\Clauses\JoinClause;

/**
 * Update view path of river entries for comments made on Tidypics images, albums and tidypics_batches (image uploads)
 *
 * This is a follow-up upgrade to be executed AFTER the Elgg core upgrade from Elgg 1.8 to Elgg 1.9.
 *
 * Due to a bug in Elgg core (fixed in 1.9.5) this upgrade here has to be repeated after upgrading to Elgg 1.9.5
 * (and possibly finishing the comments migration only now...)
 * to catch any database entries that might have not been upgraded before
 *
 * The Elgg core upgrade script changes comments from annotations to entities and updates the river entries accordingly.
 * This Tidypics-specific script then updates the views referred in river entries for comments made on Tidypics entities
 * to allow for using the Tidypics-specific river comment views (which add optionally a thumbnail image of the image/album
 * commented on and takes the specifics of commenting on tidypics_batches into account)
 */

// prevent timeout when script is running (thanks to Matt Beckett for suggesting)
set_time_limit(0);

elgg_register_plugin_hook_handler('permissions_check', 'all', 'elgg_override_permissions');
elgg_register_plugin_hook_handler('container_permissions_check', 'all', 'elgg_override_permissions');

// Make sure that entries for disabled entities also get upgraded
elgg_call(ELGG_IGNORE_ACCESS | ELGG_SHOW_DISABLED_ENTITIES, function() {

$db_prefix = elgg_get_config('dbprefix');

$image_subtype_id = get_subtype_id('object', TidypicsImage::SUBTYPE);
$album_subtype_id = get_subtype_id('object', TidypicsAlbum::SUBTYPE);
$tidypics_batch_subtype_id = get_subtype_id('object', TidypicsBatch::SUBTYPE);


// Begin of Update PART 1/5:
// After Elgg core updated the comment annotations to entities there's some additional maintenance necessary to get the Tidypics specific river views back

//
// Upgrade view paths in river table rows for comments added on Tidypics images after Elgg core converted comment annotations to entities
//

// Get river entries for comments added to Tidypics images
$batch = elgg_get_river([
	'type' => 'object',
	'subtype' => 'comment',
	'action_type' => 'comment',
	'joins' => [
		new JoinClause('entities', 'im', function(QueryBuilder $qb, $joined_alias, $main_alias) use ($user) {
			return $qb->compare("$joined_alias.guid", '=', "$main_alias.target_guid");
		}),
	],
	'wheres' => [
		function(QueryBuilder $qb) use ($image_subtype_id) {
			return $qb->compare('im.subtype', '=', $image_subtype_id);
		},
	],
	'limit' => false,
	'batch' => true,
]);

// now collect the ids of the river items that need to be upgraded
$river_entry_ids = [];
foreach ($batch as $river_entry) {
	$river_entry_ids[] = $river_entry->id;
}

// and finally update the rows in the river table if there are any rows to update
if ($river_entry_ids) {
	$river_entry_ids = implode(', ', $river_entry_ids);
	$query = "UPDATE {$db_prefix}river
		SET view = 'river/object/comment/image'
		WHERE id IN ($river_entry_ids)";
	update_data($query);
}

//
// Upgrade view paths in river table rows for comments added on Tidypics albums after Elgg core converted comment annotations to entities
//

// Get river entries for comments added to Tidypics albums
$batch = elgg_get_river([
	'type' => 'object',
	'subtype' => 'comment',
	'action_type' => 'comment',
	'joins' => [
		new JoinClause('entities', 'al', function(QueryBuilder $qb, $joined_alias, $main_alias) use ($user) {
			return $qb->compare("$joined_alias.guid", '=', "$main_alias.target_guid");
		}),
	],
	'wheres' => [
		function(QueryBuilder $qb) use ($album_subtype_id) {
			return $qb->compare('al.subtype', '=', $album_subtype_id);
		},
	],
	'limit' => false,
	'batch' => true,
]);

// now collect the ids of the river items that need to be upgraded
$river_entry_ids = [];
foreach ($batch as $river_entry) {
	$river_entry_ids[] = $river_entry->id;
}

// and finally update the rows in the river table if there are any rows to update
if ($river_entry_ids) {
	$river_entry_ids = implode(', ', $river_entry_ids);
	$query = "UPDATE {$db_prefix}river
		SET view = 'river/object/comment/album'
		WHERE id IN ($river_entry_ids)";
	update_data($query);
	unset($river_entry_ids);
}
// End of Update PART 1/5


// Begin of Update PART 2/5:
// Get river entries for comments added to Tidypics batches
// and update these entries together with the comment entities
// to point to the image (if only 1 image was uploaded) or album
$batch = elgg_get_river([
	'type' => 'object',
	'subtype' => 'comment',
	'action_type' => 'comment',
	'joins' => [
		new JoinClause('entities', 'ba', function(QueryBuilder $qb, $joined_alias, $main_alias) use ($user) {
			return $qb->compare("$joined_alias.guid", '=', "$main_alias.target_guid");
		}),
	],
	'wheres' => [
		function(QueryBuilder $qb) use ($tidypics_batch_subtype_id) {
			return $qb->compare('ba.subtype', '=', $tidypics_batch_subtype_id);
		},
	],
	'limit' => false,
	'batch' => true,
]);

foreach ($batch as $river_entry) {
	// Get the batch entity
	$tidypics_batch = get_entity($river_entry->target_guid);

	// Get images related to this batch
	$images = elgg_get_entities([
		'relationship' => 'belongs_to_batch',
		'relationship_guid' => $tidypics_batch->getGUID(),
		'inverse_relationship' => true,
		'type' => 'object',
		'subtype' => TidypicsImage::SUBTYPE,
		'limit' => false,
	]);

	// for more than a single image uploaded in the batch move the comment to the album
	if (count($images) > 1) {
		$album = get_entity($tidypics_batch->container_guid);

		// fix river entry
		$query = "
				UPDATE {$db_prefix}river
				SET access_id = {$album->access_id},
					view = 'river/object/comment/album',
					target_guid = {$album->guid}
				WHERE id = {$river_entry->id}
		";
		update_data($query);

		// and fix comment entity
		$comment_entity = get_entity($river_entry->object_guid);
		$query = "
				UPDATE {$db_prefix}entities
				SET container_guid = {$album->guid},
					access_id = {$album->access_id}
				WHERE guid = {$comment_entity->guid}
		";
		update_data($query);
	// for a batch with only a single image uploaded move the comment to this image
	} else {
		// fix river entry
		$query = "
				UPDATE {$db_prefix}river
				SET access_id = {$images[0]->access_id},
					view = 'river/object/comment/image',
					access_id = {$images[0]->access_id},
					target_guid = {$images[0]->guid}
				WHERE id = {$river_entry->id}
		";
		update_data($query);

		// and fix comment entity
		$comment_entity = get_entity($river_entry->object_guid);
		$query = "
				UPDATE {$db_prefix}entities
				SET container_guid = {$images[0]->guid},
					access_id = {$images[0]->access_id}
				WHERE guid = {$comment_entity->guid}
		";
		update_data($query);
	}
}
// End of Update Part 2/5


// Begin of Update PART 3/5:
// Get comment entities (former annotations) added to Tidypics batches with a Tidypics 1.8.1betaXX version
// and update these entries together with their river entries to be assigned
// to the image (if only 1 image was uploaded) or album
$batch = elgg_get_entities([
	'type' => 'object',
	'subtype' => 'comment',
	'joins' => [
		new JoinClause('entities', 'ba', function(QueryBuilder $qb, $joined_alias, $main_alias) use ($user) {
			return $qb->compare("$joined_alias.guid", '=', "$main_alias.container_guid");
		}),
	],
	'wheres' => [
		function(QueryBuilder $qb) use ($tidypics_batch_subtype_id) {
			return $qb->compare('ba.subtype', '=', $tidypics_batch_subtype_id);
		},
	],
	'limit' => false,
	'batch' => true,
]);

foreach ($batch as $comment_entity_entry) {

	// Get the batch entity
	$tidypics_batch = get_entity($comment_entity_entry->container_guid);

	// Get images related to this batch
	$images = elgg_get_entities([
		'relationship' => 'belongs_to_batch',
		'relationship_guid' => $tidypics_batch->getGUID(),
		'inverse_relationship' => true,
		'type' => 'object',
		'subtype' => TidypicsImage::SUBTYPE,
		'limit' => false,
	]);

	// for more than a single image uploaded in the batch move the comment to the album
	if (count($images) > 1) {
		$album = get_entity($tidypics_batch->container_guid);

		// fix river entry
		$query = "
				UPDATE {$db_prefix}river
				SET access_id = {$album->access_id},
					view = 'river/object/comment/album',
					target_guid = {$album->guid}
				WHERE object_guid = {$comment_entity_entry->guid}
		";
		update_data($query);

		// and fix comment entity
		$query = "
				UPDATE {$db_prefix}entities
				SET container_guid = {$album->guid},
					access_id = {$album->access_id}
				WHERE guid = {$comment_entity_entry->guid}
		";
		update_data($query);
	// for a batch with only a single image uploaded move the comment to this image
	} else {
		// fix river entry
		$query = "
				UPDATE {$db_prefix}river
				SET access_id = {$images[0]->access_id},
					view = 'river/object/comment/image',
					target_guid = {$images[0]->guid}
				WHERE object_guid = {$comment_entity_entry->guid}
		";
		update_data($query);

		// and fix comment entity
		$query = "
				UPDATE {$db_prefix}entities
				SET container_guid = {$images[0]->guid},
					access_id = {$images[0]->access_id}
				WHERE guid = {$comment_entity_entry->guid}
		";
		update_data($query);
	}
}
// End of Update PART 3/5


// Begin of Update PART 4/5:
// Check for album comments without a river entry and delete them (for each comment made on the activity page
// on an image upload a second comment annotation (on Elgg 1.9 it has been converted to an entity) was created
// with Tidypics 1.8.1betaXX that showed up on the corresponding album page while the first comment annotation
// was only visible below the river entry on the activity page. This second comment annotation is now no longer
// necessary and should be removed to avoid comments appearing twice on album pages)
// ATTENTION: this part of the upgrade script will remove ALL album comment entities (formerly annotations on Elgg 1.9)
// that don't a corresponding river entry. If you removed the river entries by any means or prevented them from getting
// created in the first place you might not want this part of the upgrade to be executed. Then you should comment out part 4
// of the upgrade. But you will most likely end with double comments on album pages then.
$batch = elgg_get_entities([
	'type' => 'object',
	'subtype' => 'comment',
	'joins' => [
		new JoinClause('entities', 'al', function(QueryBuilder $qb, $joined_alias, $main_alias) use ($user) {
			return $qb->compare("$joined_alias.guid", '=', "$main_alias.container_guid");
		}),
	],
	'wheres' => [
		function(QueryBuilder $qb) use ($album_subtype_id) {
			return $qb->compare('al.subtype', '=', $album_subtype_id);
		},
	],
	'limit' => false,
	'batch' => true,
]);
// now collect the ids of the duplicate comment entities that should be deleted
$album_comment_entry_guids = [];
foreach ($batch as $album_comment) {
	$river_entry_count = elgg_get_river([
		'type' => 'object',
		'subtype' => 'comment',
		'action_type' => 'comment',
		'object_guid' => $album_comment->guid,
		'target_guid' => $album_comment->container_guid,
		'count' => true,
	]);
	
	if($river_entry_count < 1) {
		$album_comment_entry_guids[] = $album_comment->guid;
	}
}

// and finally delete the rows in the entities table if there have been any duplicate comment entities found
if ($album_comment_entry_guids) {
	$album_comment_entry_guids = implode(', ', $album_comment_entry_guids);
	$del_album_entity_query = "DELETE FROM {$db_prefix}entities
							WHERE guid IN ($album_comment_entry_guids)";
	delete_data($del_album_entity_query);
	unset($album_comment_entry_guids);
}
// End of Update Part 4/5

// Begin of Update Part 5/5
// Update likes made to Tidypics batches and assign them either to the image uploaded (if only one) or the album
$batch = elgg_get_annotations([
	'annotation_name' => 'likes',
	'joins' => [
		new JoinClause('entities', 'li', function(QueryBuilder $qb, $joined_alias, $main_alias) use ($user) {
			return $qb->compare("$joined_alias.guid", '=', "$main_alias.entity_guid");
		}),
	],
	'wheres' => [
		function(QueryBuilder $qb) use ($tidypics_batch_subtype_id) {
			return $qb->compare('li.subtype', '=', $tidypics_batch_subtype_id);
		},
	],
	'limit' => false,
	'batch' => true,
]);
// collect the ids of like annotations that would be duplicates after assinging them to images or albums
$like_annotation_ids = [];
foreach ($batch as $like_entry) {
	// Get the batch entity
	$tidypics_batch = get_entity($like_entry->entity_guid);

	// Get images related to this batch
	$images = elgg_get_entities([
		'relationship' => 'belongs_to_batch',
		'relationship_guid' => $tidypics_batch->getGUID(),
		'inverse_relationship' => true,
		'type' => 'object',
		'subtype' => TidypicsImage::SUBTYPE,
		'limit' => false,
	]);

	// move the like to the album if more than a single image was uploaded in this batch
	if (count($images) > 1) {
		$album = get_entity($tidypics_batch->container_guid);
		// in case the same user who liked the Tidypics batch entry on the activity already
		// liked the album delete the annotation to prevent double likes
		if (elgg_annotation_exists($album->guid, 'likes', $like_entry->owner_guid)) {
			$like_annotation_ids[] = $like_entry->id; // deleting follows later
		} else {
			// fix annotation
			$query = "
					UPDATE {$db_prefix}annotations
					SET entity_guid = {$album->guid},
						access_id = {$album->access_id}
					WHERE id = {$like_entry->id}
			";
			update_data($query);
		}
	// move the like to the image if only a single image was uploaded in the batch
	} else {
		// in case the same user who liked the Tidypics batch entry on the activity already
		// liked the image delete the annotation to prevent double likes
		if (elgg_annotation_exists($images[0]->guid, 'likes', $like_entry->owner_guid)) {
			$like_annotation_ids[] = $like_entry->id; // deleting follows later
		} else {
			// fix annotation
			$query = "
					UPDATE {$db_prefix}annotations
					SET entity_guid = {$images[0]->guid},
						access_id = {$images[0]->access_id}
					WHERE id = {$like_entry->id}
			";
			update_data($query);
		}
	}
}

// and finally delete the rows in the annotations table if there have been any duplicate likes found
if ($like_annotation_ids) {
	$like_annotation_ids = implode(', ', $like_annotation_ids);
	$del_like_annotations_query = "DELETE FROM {$db_prefix}annotations
							WHERE id IN ($like_annotation_ids)";
	delete_data($del_like_annotations_query);
	unset($like_annotation_ids);
}
// End of Update Part 5/5

});

<?php
declare(encoding="UTF-8");

/**
 * @copyright 2010 YoHOLLA International Inc.. All rights are reserved.
 * @author CJ Holmes <cj.holmes@yoholla.com>
 * @package Y
 * @subpackage Api
 *
 *
 *	This is a typical for the kind of SOA interface I used to write for YoHolla.  One of the most 
 *	important things to notice here is the JavaDoc-style annotations.  The service framework would
 *	read these annotations and know which methods should be exposed, how they should be treated, 
 *	what types of parameters they would accept, what versions of the API they were valid for, 
 *	and so on.
 *
 *	Our service framework was also self-documenting.  A third-party developer could go to our site 
 *	and look up any API for any released version of the web site, and get basic documentation about 
 *	it.
 */

/**
 *	A service for marking up data in various ways
 *
 *	This API encompases:
 *	- Comments: Comment on an item that you can see.
 *	- Tags: Associate a person with an object.  (Eg: Alice is in this Photo)
 *	- Ratings: Say how much you like something, or find out how popular something is. (Alice's photo is 5-star!)
 *	- Marks: Complain about something on YoHolla (Alice shouldn't put nude photos of herself on YoHolla)
 *
 *	@Service
 */
class Y_Api_Meta {

	/**
	 *	Create a comment to an Item
	 *
	 *	The caller may create a comment in response to any item he can see, or any comment to an
	 *	item that he can see.  Comments are created from an array with the parent item, the comment
	 *	text, and some optional params.  Example:
	 *
	 *	The various fields are:
	 *	- parent parameter must be an item that the caller is allowed to see.
	 *	- inReplyTo is optional.  If present, it must be another comment with the same parent as the new comment.
	 *	- private indicates a private comment.  It will only be visible between the commenter and the owner of the parent object.
	 *	- text is the body of the comment
	 *	- textType is optional, and can be any text type supported by the system.  'plain' is the only value supported presently.
	 *
	 *	By default, a comment is visible to anyone who could see the parent object.  If you turn on
	 *	private flag, then the comment is visible only to the parent's owner or the
	 *	inReplyTo comment's owner.
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $parent The object ID of the thing you are commenting on
	 *	@param Y_Data_String $text The text of the comment
	 *	@param Y_Data_String $replyTo If this is a follow-on comment, $replyTo is the id of the comment being replied to.
	 *	@param Y_Data_Boolean $private If set, this comment will be private between the poster and the parent's owner
	 *	@param Y_Data_String $type The type of text in the comment
	 *
	 *	@return Y_Data_Object The new comment
	 *
	 *	@throws Y_EntityConstraint
	 *
	 *	@Route
	 */
	public static function createComment(Y_Auth $auth, Y_Data_String $parent, Y_Data_String $text,
		Y_Data_String $replyTo=null, Y_Data_Boolean $private=null, Y_Data_String $type=null) {

		$comArgs = array(
			'parent' => $parent->getValue(),
			'text' => $text->getValue()
		);

		if ($replyTo !== null)
			$comArgs['inReplyTo'] = $replyTo->getValue();
		if ($private !== null)
			$comArgs['private'] = $private->getValue();
		if ($type !== null)
			$comArgs['textType'] = $type->getValue();

		$comment = Y_Entity_Comment::createFromArray($auth, $comArgs);
		Y_Api_Profile::commentEvent($auth, $comment,$text);
		return Y_Entity_DataConverter::entityToObject($comment);
	}

	/**
	 *	Get all comments for an item.
	 *
	 *	Comments will be in forward-chronological order.
	 *
	 *	The output is an array of comments.  In JSON, it should look something like this:
	 *	<code>
	 *	[	{	"_id": ".....",
	 *			"_sender": "larry",
	 *			"_apparentlyFrom": "larry",
	 *			"_parent": "<parent-post-id>",
	 *			"_inReplyTo": "<parent-post-or-comment-id>",
	 *			"_isPrivate": false,
	 *			"_acl": [ 'larry', 'larry:ZON:close-friends', 'larry:ZON:work-buddies' ]
	 *			'_createTime': 1234567,
	 *			'_lastmodTime': 1234569
	 *			"textType": "plain",
	 *			"text": "What's up?",
	 *		},
	 *		{
	 *			...
	 *		}
	 *	]
	 *	</code>
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $id The id of the item whose comments you wish to retrieve
	 *	@return Y_Data_Object[] An array of comments, all of them for the same post.
	 *	@throws Y_InvalidArgumentException
	 *
	 *	@Route(regex='/^comment\/for\/(?<id>[a-z0-9\:\.\-_]+)$/i', type='PATH')
	 */
	public static function getCommentsFor(Y_Auth $auth, Y_Data_String $id) {
		$retval = new Y_Data_Array();

		$id = $id->getValue();
		$comments = Y_Entity_Comment::aboutEntity($auth, $id);

		return Y_Entity_DataConverter::somethingToData($comments);
	}


	/**
	 *	Deletes a comment
	 *
	 *	Nothing is every truly deleted.  It just moves to the archive.
	 *
	 *	Comments can be deleted by the person who created them, or by the owners of the parent
	 *	item.
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param string $id The id of the comment to delete
	 *
	 *	@return Y_Data_Boolean True on success
	 *
	 *	@throws Y_EntityConstraint
	 *
	 *	@Route
	 */
	public static function deleteComment(Y_Auth $auth, Y_Data_String $id) {
		$id = $id->getValue();

		$leComment = Y_EntityFactory::getEntityById($auth, $id);
		if ($leComment)
			$leComment->archive();

		return new Y_Data_Boolean(true);
	}

	/**
	 *	Tag someone to an item
	 *
	 *	You can tag yourself to an item you can see, or you can tag a friend to an item you
	 *	own.
	 *
	 *	When you tag someone (the subject), you associate them with an item of some kind (the parent).
	 *	When the parent item is displayed, a link to the subject's profile can be displayed alongside
	 *	it.
	 *
	 *	People can only be tagged if they allow it in their profile setting.  You can only tag people
	 *	to parents you own, and you can tag yourself to parents you can see.
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $parent The ID of the item receiving the tag
	 *	@param Y_Data_Username[] $subjects The person being tagged
	 *	@return Y_Data_Object[] The newly-created tags.
	 *	@Route
	 */
	public static function tag(Y_Auth $auth, Y_Data_String $parent, Y_Data_Array $subjects) {
		$retval = new Y_Data_Array();

		// With such a simple entity type, it would be a waste to make a special API.
		foreach($subjects as $subject) {
			try {
				$tag = Y_EntityFactory::createEntityType($auth, Y_Entity::TAG_TYPE);
				$tag->setParentId($parent->getValue());
				$tag->setSubject($subject->getValue());
				$tag->create();
				$retval[] = Y_Entity_DataConverter::entityToObject($tag);
			} catch(Y_EntityConstraint $yec) { ; }
		}

		return $retval;
	}

	/**
	 *	Get the tags for an item
	 *
	 *	When displaying an item, you'll often want to display all the tags associated with it.  Each
	 *	tag will contain:
	 *	- _id
	 *	- _parent (the parent item)
	 *	- title (the title of the parent, if present)
	 *	- _subject
	 *	- displayName (the display name name of the subject)
	 *	- _lastmodTime
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $parent The parent of the tags
	 *	@return Y_Data_Object[] An array of tags
	 *	@Route
	 */
	public static function getTagsOn(Y_Auth $auth, Y_Data_String $parent) {
		$retval = Y_Entity_Tag::getTagsOn($auth, $parent->getValue());
		return Y_Entity_DataConverter::somethingToData($retval);
	}

	/**
	 *	Get all the tags for a person
	 *
	 *	In your profile, you can choose to display all the places where you have been tagged.  The
	 *	format is the same as in
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_Member $subject The person who was tagged
	 *	@return Y_Data_Object[] An array of tags
	 *	@Route
	 */
	public static function getTagsAbout(Y_Auth $auth, Y_Data_Username $subject) {
		$retval = Y_Entity_Tag::getTagsAbout($auth, $subject->getValue());
		return Y_Entity_DataConverter::somethingToData($retval);
	}

	/**
	 *	Remove a tag
	 *
	 *	You can remove the tag if you are the subject, or if you own the parent
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $parent The ID of the item receiving the tag
	 *	@param Y_Data_Username[] $subjects The person being tagged
	 *	@return Y_Data_Integer The number of tags removed.  Returns 0 on total failure.
	 *
	 *	@throws Y_EntityConstraint If you aren't allowed to delete the tag(s).
	 *
	 *	@Route
	 */
	public static function untag(Y_Auth $auth, Y_Data_String $parent, Y_Data_Array $subjects) {
		$retval = 0;

		$subjArray = array();
		foreach($subjects as $oneSubject)
			$subjArray[] = $oneSubject->getValue();

		try {
			$collection = Y_Mongo::singleton()->selectCollection(Y_Entity_Tag::getCollection());
			$cursor = $collection->find( array (
				Y_Entity::PARENT_FIELD => $parent->getValue(),
				Y_Entity_Tag::SUBJECT_FIELD => array ( '$in'=>$subjArray )
			) );
			$cursor->slaveOkay();
			foreach($cursor as $data) {
				$ent = Y_EntityFactory::createFromData($auth, $data);
				if($ent) {
					$ent->delete();
					$retval++;
				}
			}
		} catch(MongoException $mex) {
			Y_Log::error($mex->getMessage());
			throw new Y_Exception('Database error');
		}

		return new Y_Data_Integer($retval);
	}

	/**
	 *	Add a rating for an item.
	 *
	 *	A user can only rate an item one time.  If they rate it again, the new rating replaces
	 *	the old one.
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $item the ID of the thing to be rated
	 *	@param Y_Data_Integer $score The score, a number from 1 to 5
	 *	@return Y_Data_Object The count and score of the item's rating
	 *	@Route
	 */
	public static function rateItem(Y_Auth $auth, Y_Data_String $item, Y_Data_Integer $score) {
		$vote = Y_Entity_Rating::rateItem($auth, $item->getValue(), $score->getValue());
		$rating = Y_Entity_Rating::getRating($auth, $item->getValue());
		return Y_Entity_DataConverter::somethingToData( (object)$rating );
	}

	/**
	 *	Get the rating for an item
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $item the ID of the target item
	 *	@return Y_Data_Object The count and score of the item's rating
	 *	@Route
	 */
	public static function getRating(Y_Auth $auth, Y_Data_String $item) {
		$response = Y_Entity_Rating::getRating($auth, $item->getValue());
		return Y_Entity_DataConverter::somethingToData( (object)$response );
	}

	/**
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $item The ID of the target item
	 *	@return Y_Data_Object Histogram of data
	 *	@Route
	 */
	public static function getHistogram(Y_Auth $auth, Y_Data_String $item) {
		$response = Y_Entity_Rating::getHistogram($auth, $item->getValue());
		return Y_Entity_DataConverter::somethingToData( $response['hist'] );
	}


	/**
	 *	Mark an item as being objectionable.
	 *
	 *	You can only mark things that you can see.  If you can't see it, then you can't mark it.
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $item The object ID of the thing you are marking
	 *	@param Y_Data_MarkType $type The type of mark we're placing on the object
	 *	@param Y_Data_String $comment A brief comment about what the caller didn't like
	 *	@return Y_Data_Boolean True on success, false otherwise.
	 *	@throws Y_EntityConstraint
	 *
	 *	@Route
	 */
	public static function markItem(Y_Auth $auth, Y_Data_String $item, Y_Data_MarkType $type,
	 Y_Data_String $comment=null) {
		$ent = Y_EntityFactory::createEntityType($auth, Y_Entity::MARK_TYPE);
		$ent->setParentId($item->getValue());
		$ent->setComplaint($type->getValue());
		if($comment)
			$ent->comment = $comment->getValue();
		$ent->create();

		return new Y_Data_Boolean(true);
	}


	/**
	 *	Will let you know how many other people found this item to be objectionable
	 *
	 *	The information you get back is:
	 *	- spam: The number of spam markings
	 *	- abuse: The number of abuse markings
	 *	- inappropriate: The number of inappropriate markings
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $item The object ID of the thing you are summarizing
	 *	@return Y_Data_Object Summary information about the reputation of the object.
	 *	@throws Y_EntityConstraint
	 *
	 *	@Route
	 */
	public static function getItemReputation(Y_Auth $auth, Y_Data_String $item) {
		return Y_Entity_DataConverter::somethingToData(
			Y_Entity_Mark::getItemReputation($auth, $item->getValue()) );
	}

	/**
	 *	Lists all the marks, in detail, about this item.
	 *
	 *	Each mark contains the following information:
	 *	- _id: The id of the mark itself
	 *	- _parentId: The id of the thing marked
	 *	- _ownerId: The person who created the mark
	 *	- _lastmodTime: when the mark was created
	 *	- type: the type of mark
	 *
	 *	You can only list the marks for an item that you can see.
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $item The object ID of the item which you are examining
	 *	@return Y_Data_Object[] Current information about the reputation of the object.
	 *	@throws Y_EntityConstraint
	 *
	 *	@Route
	 *	@todo Restrict to admin
	 */
	public static function getItemMarks(Y_Auth $auth, Y_Data_String $item) {
		return Y_Entity_DataConverter::somethingToData(
			Y_Entity_Mark::getItemMarks($auth, $item->getValue()) );
	}

	/**
	 *	Marks an item as resolved
	 *
	 *	If an item is resolved 'allow' then the administrators have decided that, although the
	 *	complaints may have some merit, the item can remain on YoHolla.
	 *
	 *	If an item is resolved 'censure' then it is deemed genuinely unacceptable by the admins, who
	 *	will remove the item to where it can't be seen by anyone (including the owner).
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_String $id The id of the item to resolve
	 *	@param Y_Data_MarkResolution $resolution The determination (censure,allow)
	 *	@param Y_DataString $comment Whatever the admins had to say about the issue
	 *	@return Y_Data_Boolean True if the operation was successful
	 *
	 *	@Route
	 *	@todo Restrict to admin
	 */
	public static function resolveMarkedItem(Y_Auth $auth, Y_Data_String $id,
	 Y_Data_MarkDisposition $resolution, Y_Data_String $comment) {
		switch($resolution->getValue()) {
			case 'allow':
				Y_Entity_Mark::allowItem($auth, $id->getValue(), $comment->getValue());
				break;
			case 'censure':
				Y_Entity_Mark::censureItem($auth, $id->getValue(), $comment->getValue());
				break;
		}

		return new Y_Data_Boolean(true);
	}

	/**
	 *	Creates a report about the most-marked items in a given time period.
	 *
	 *	You get back an array of objects that were marked in the time interval given, sorted from
	 *	the most-marked to the least-marked.  The data in each object includes:
	 *	- _id: The id of the item
	 *	- _type: The type of item
	 *	- _ownerId: The owner of the item
	 *	- title: The title of the item, if it has one
	 *	- spam: The number of spam marks
	 *	- abuse: the number of abuse marks
	 *	- inappropriate: The number of inappropriate marks
	 *	- total: The total score for the item
	 *
	 *	@param Y_Auth $auth The caller's credentials
	 *	@param Y_Data_Date $start The starting time, in UTC
	 *	@param Y_Data_Date $end The ending time, in UTC
	 *	@param Y_Data_MarkDisposition $disposition Whether to include resolved items.  Defaults to none.
	 *	@return Y_Data_Object[] A series of records, starting with the highest scores.
	 *	@throws Y_EntityConstraint
	 *
	 *	@Route
	 *	@todo Restrict to admin
	 */
	public static function getMarkReport(Y_Auth $auth, Y_Data_Date $start, Y_Data_Date $end=null,
	 Y_Data_MarkDisposition $disposition=null) {

	 	$start = $start->getAsInt();
	 	if($end !== null)
	 		$end = $end->getAsInt();
	 	if($disposition !== null)
	 		$disposition = $disposition->getValue();

		return Y_Entity_DataConverter::somethingToData(
			Y_Entity_Mark::reportMarkedItems($auth, $start, $end, $disposition)
		);
	}

	/**
	 * Retrieves the children for a specified entity
	 *
	 * @param Y_Auth $auth Authentication object
	 * @param Y_Data_String $id Entity id to retrieve the children for
	 *
	 * @return Y_Data_String[] Array of the entity's children's ids
	 *
	 * @Route
	 */
	public static function getChildren(Y_Auth $auth, Y_Data_String $id) {
		$entity = Y_EntityFactory::getEntityById($auth, $id->getValue());

		if ($entity === null) {
			throw new Y_Exception('Invalid entity id');
		}

		$ret = new Y_Data_Array();
		$entities = Y_EntityFactory::find($auth, null, null, $id->getValue(), null,
				Y_EntityFactory::DEFAULT_LIMIT, Y_EntityFactory::DEFAULT_OFFSET, array('_lastmodTime' => -1));
		foreach ($entities as $e) {
			$ret[] = new Y_Data_String($e->getId());
		}

		return $ret;
	}
}

<?php

/**
 * This file contains the Pulse class
 *
 * @copyright 2015 Vladimir Jimenez
 * @license   https://github.com/allejo/PhpPulse/blob/master/LICENSE.md MIT
 */

namespace allejo\DaPulse;

use allejo\DaPulse\Exceptions\HttpException;
use allejo\DaPulse\Exceptions\InvalidColumnException;
use allejo\DaPulse\Exceptions\InvalidObjectException;
use allejo\DaPulse\Objects\ApiObject;
use allejo\DaPulse\Objects\PulseColumnStatusValue;
use allejo\DaPulse\Objects\PulseColumnDateValue;
use allejo\DaPulse\Objects\PulseColumnPersonValue;
use allejo\DaPulse\Objects\PulseColumnTextValue;
use allejo\DaPulse\Objects\PulseColumnValue;
use allejo\DaPulse\Utilities\ArrayUtilities;

/**
 * A class representing a single pulse in a board
 *
 * @api
 * @package allejo\DaPulse
 * @since 0.1.0
 */
class Pulse extends ApiObject
{
    /**
     * @ignore
     */
    const API_PREFIX = "pulses";

    // ================================================================================================================
    //   Instance Variables
    // ================================================================================================================

    /**
     * The resource's URL.
     *
     * @var string
     */
    protected $url;

    /**
     * The pulse's unique identifier.
     *
     * @var int
     */
    protected $id;

    /**
     * The pulse's name.
     *
     * @var string
     */
    protected $name;

    /**
     * The board's subscribers.
     *
     * @var PulseUser[]
     */
    protected $subscribers;

    /**
     * The amount of updates a pulse has.
     *
     * @var int
     */
    protected $updates_count;

    /**
     * The ID of the parent board.
     *
     * @var int
     */
    protected $board_id;

    /**
     * Creation time.
     *
     * @var \DateTime
     */
    protected $created_at;

    /**
     * Last update time.
     *
     * @var \DateTime
     */
    protected $updated_at;

    /**
     * The ID of the group this pulse belongs to
     *
     * @var string
     */
    protected $group_id;

    /**
     * @var PulseColumn[]
     */
    protected $column_structure;

    /**
     * An array containing all of the values a pulse has for each column
     *
     * @var mixed
     */
    protected $raw_column_values;

    /**
     * An array containing objects extended from PulseColumnValue storing all of the values for each column
     *
     * @var array
     */
    protected $column_values;

    /**
     * The common URL path for retrieving objects relating a pulse such as subscribers, notes, or updates
     *
     * @var string
     */
    private $urlSyntax = "%s/%s/%s.json";

    // ================================================================================================================
    //   Overloaded functions
    // ================================================================================================================

    protected function initializeValues ()
    {
        $this->column_values     = array();
        $this->column_structure  = array();
        $this->raw_column_values = array();
    }

    // ================================================================================================================
    //   Getter functions
    // ================================================================================================================

    /**
     * The resource's URL.
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * The pulse's unique identifier.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * The pulse's name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * The amount of updates a pulse has.
     *
     * @return int
     */
    public function getUpdatesCount()
    {
        return $this->updates_count;
    }

    /**
     * The ID of the parent board.
     *
     * @return int
     */
    public function getBoardId()
    {
        return $this->board_id;
    }

    /**
     * Creation time.
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        self::lazyLoad($this->created_at, '\DateTime');

        return $this->created_at;
    }

    /**
     * Last update time.
     *
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        self::lazyLoad($this->updated_at, '\DateTime');

        return $this->updated_at;
    }

    /**
     * Get the ID of the group this Pulse is a part of. If this value is not available, an API call will be made to
     * find the group ID via brute force.
     *
     * **Note** The group ID is cached if it is not available. To update the cached value, use $forceFetch to force an
     * API call to get a new value.
     *
     * **Warning** An API call is always slower than using the cached value.
     *
     * @param bool $forceFetch Force an API call to get an updated group ID if it has been changed
     * @since 0.1.0
     * @return string
     */
    public function getGroupId($forceFetch = false)
    {
        if (empty($this->group_id) || $forceFetch)
        {
            $parentBoard = new PulseBoard($this->board_id);
            $pulses = $parentBoard->getPulses();

            foreach ($pulses as $pulse)
            {
                if ($this->getId() === $pulse->getId())
                {
                    $this->group_id = $pulse->getGroupId();
                    break;
                }
            }
        }

        return $this->group_id;
    }

    // ================================================================================================================
    //   Pulse functions
    // ================================================================================================================

    /**
     * Delete the current Pulse
     *
     * @api
     * @throws \allejo\DaPulse\Exceptions\InvalidObjectException
     */
    public function deletePulse ()
    {
        $this->checkInvalid();

        $deleteURL = sprintf("%s/%d.json", self::apiEndpoint(), $this->getId());

        self::sendDelete($deleteURL);

        $this->deletedObject = true;
    }

    public function duplicatePulse ($group_id = null, $owner_id = null)
    {
        $url = sprintf("%s/%s/pulses/%s/duplicate.json", parent::apiEndpoint("boards"), $this->getBoardId(), $this->getId());
        $postParams = array();

        if ($owner_id instanceof PulseUser)
        {
            $owner_id = $owner_id->getId();
        }

        self::setIfNotNullOrEmpty($postParams, "group_id", $group_id);
        self::setIfNotNullOrEmpty($postParams, "owner_id", $owner_id);

        $result = self::sendPost($url, $postParams);
        $this->pulseInjection($result);

        return (new Pulse($result['pulse']));
    }

    private function pulseInjection (&$result)
    {
        $parentBoard = new PulseBoard($this->getBoardId());

        // Inject some information so a Pulse object can survive on its own
        $result["pulse"]["group_id"] = $result["board_meta"]["group_id"];
        $result["pulse"]["column_structure"] = $parentBoard->getColumns();
        $result["pulse"]["raw_column_values"] = $result["column_values"];
    }

    // ================================================================================================================
    //   Column data functions
    // ================================================================================================================

    /**
     * Access a pulse's specific column to either access their value or to modify the value.
     *
     * See the related functions to see the appropriate replacements.
     *
     * @todo This function only exists for legacy applications. Remove in 0.1.1
     *
     * @api
     * @deprecated 0.0.1 This function will be removed by 0.1.1. New stricter functions are available
     *
     * @param string $columnId The ID of the column to access. It's typically a slugified version of the column title
     *
     * @see Pulse::getStatusColumn()  getColorColumn()
     * @see Pulse::getDateColumn()   getDateColumn()
     * @see Pulse::getPersonColumn() getPersonColumn()
     * @see Pulse::getTextColumn()   getTextColumn()
     * @since 0.1.0
     * @throws InvalidObjectException The specified column exists but modification of its value is unsupported either
     *                                by this library or the DaPulse API.
     * @throws InvalidColumnException   The specified column ID does not exist for this Pulse
     * @return PulseColumnValue The returned object will be a child of this abstract class.
     */
    public function getColumnValue ($columnId)
    {
        if (!isset($this->column_values) || !array_key_exists($columnId, $this->column_values))
        {
            $key = ArrayUtilities::array_search_column($this->raw_column_values, 'cid', $columnId);

            $data = $this->raw_column_values[$key];
            $type = $this->column_structure[$key]->getType();

            $data['column_id'] = $data['cid'];
            $data['board_id'] = $this->getBoardId();
            $data['pulse_id'] = $this->getId();

            $this->column_values[$columnId] = PulseColumnValue::_createColumnType($type, $data);
        }

        return $this->column_values[$columnId];
    }

    /**
     * Access a color type column value belonging to this pulse in order to read it or modify.
     *
     * This function should only be used to access color type values; an exception will be thrown otherwise.
     *
     * @api
     *
     * @param string $columnId The ID of the column to access. This is typically a slugified version of the column name
     *
     * @since 0.1.0
     * @throws InvalidColumnException The specified column is not a "color" type column
     * @throws InvalidObjectException The specified column exists but modification of its value is unsupported either
     *                                by this library or the DaPulse API.
     * @throws InvalidColumnException   The specified column ID does not exist for this Pulse
     * @return PulseColumnStatusValue A column object with access to its contents
     */
    public function getStatusColumn ($columnId)
    {
        return $this->getColumn($columnId, PulseColumn::Status);
    }

    /**
     * Access a date type column value belonging to this pulse in order to read it or modify.
     *
     * This function should only be used to access data type values; an exception will be thrown otherwise.
     *
     * @api
     * @param string $columnId The ID of the column to access. This is typically a slugified version of the column name
     * @since 0.1.0
     * @throws InvalidColumnException The specified column is not a "date" type column
     * @throws InvalidObjectException The specified column exists but modification of its value is unsupported either
     *                                by this library or the DaPulse API.
     * @throws InvalidColumnException   The specified column ID does not exist for this Pulse
     * @return PulseColumnDateValue A column object with access to its contents
     */
    public function getDateColumn ($columnId)
    {
        return $this->getColumn($columnId, PulseColumn::Date);
    }

    /**
     * Access a person type column value belonging to this pulse in order to read it or modify.
     *
     * This function should only be used to access person type values; an exception will be thrown otherwise.
     *
     * @api
     * @param string $columnId The ID of the column to access. This is typically a slugified version of the column name
     * @since 0.1.0
     * @throws InvalidColumnException The specified column is not a "person" type column
     * @throws InvalidObjectException The specified column exists but modification of its value is unsupported either
     *                                by this library or the DaPulse API.
     * @throws InvalidColumnException   The specified column ID does not exist for this Pulse
     * @return PulseColumnPersonValue A column object with access to its contents
     */
    public function getPersonColumn ($columnId)
    {
        return $this->getColumn($columnId, PulseColumn::Person);
    }

    /**
     * Access a text type column value belonging to this pulse in order to read it or modify.
     *
     * This function should only be used to access text type values; an exception will be thrown otherwise.
     *
     * @api
     * @param string $columnId The ID of the column to access. This is typically a slugified version of the column name
     * @since 0.1.0
     * @throws InvalidColumnException The specified column is not a "text" type column
     * @throws InvalidObjectException The specified column exists but modification of its value is unsupported either
     *                                by this library or the DaPulse API.
     * @throws InvalidColumnException   The specified column ID does not exist for this Pulse
     * @return PulseColumnTextValue A column object with access to its contents
     */
    public function getTextColumn ($columnId)
    {
        return $this->getColumn($columnId, PulseColumn::Text);
    }

    /**
     * Build a pulse's column object if it doesn't exist or return the existing column.
     *
     * @param string $columnId   The ID of the column to access. This is typically a slugified version of the column
     *                           title
     * @param string $columnType The type of column being accessed: 'text', 'color', 'person', or 'date'
     *
     * @since 0.1.0
     *
     * @throws InvalidColumnException The specified column is not the same type as specified in `$columnType`
     * @throws InvalidObjectException The specified column exists but modification of its value is unsupported either
     *                                by this library or the DaPulse API.
     * @throws InvalidColumnException   The specified column ID does not exist for this Pulse
     *
     * @return PulseColumnValue The returned object will be a child of this abstract class.
     */
    private function getColumn ($columnId, $columnType)
    {
        if (!isset($this->column_values) || !array_key_exists($columnId, $this->column_values))
        {
            $key = ArrayUtilities::array_search_column($this->raw_column_values, 'cid', $columnId);

            // We can't find the key, this means that we got our information from accessing a Pulse directly instead of
            // getting it through a PulseBoard. This isn't as robust as accessing a PulseBoard but it's more efficient.
            // We make a separate API call to get the value of a column.
            if ($key === false)
            {
                $url = sprintf("%s/%d/columns/%s/value.json", parent::apiEndpoint("boards"), $this->getBoardId(), $columnId);
                $params = array(
                    "pulse_id" => $this->getId()
                );

                try
                {
                    $results = parent::sendGet($url, $params);
                }
                catch (HttpException $e)
                {
                    throw new InvalidColumnException("The '$columnId' column could not be found");
                }

                // Store our value inside of jsonResponse so all of the respective objects can treat the data the same
                // as when accessed through a PulseBoard
                $data['jsonResponse']['value'] = $results['value'];
            }
            else
            {
                $data = $this->raw_column_values[$key];
                $type = $this->column_structure[$key]->getType();

                if ($type !== $columnType)
                {
                    throw new InvalidColumnException("The '$columnId' column was expected to be '$columnType' but was '$type' instead.");
                }
            }

            $data['column_id'] = $columnId;
            $data['board_id'] = $this->getBoardId();
            $data['pulse_id'] = $this->getId();

            $this->column_values[$columnId] = PulseColumnValue::_createColumnType($columnType, $data);
        }

        return $this->column_values[$columnId];
    }

    // ================================================================================================================
    //   Subscribers functions
    // ================================================================================================================

    /**
     * Subscribe a user to a pulse
     *
     * @api
     *
     * @param  int|PulseUser $user_id  The user that will be subscribed
     * @param  bool|null     $as_admin True to make them an admin of the Pulse
     *
     * @since  0.1.0
     *
     * @throws HttpException Subscribing a user failed; access the exception for more information.
     */
    public function addSubscriber ($user_id, $as_admin = null)
    {
        if ($user_id instanceof PulseUser)
        {
            $user_id = $user_id->getId();
        }

        $url = sprintf("%s/%d/subscribers.json", parent::apiEndpoint(), $this->getId());
        $params = array(
            "user_id" => $user_id
        );

        parent::setIfNotNullOrEmpty($params, "as_admin", $as_admin);
        parent::sendPut($url, $params);
    }

    /**
     * Access a pulse's subscribers
     *
     * To modify the amount of data returned with pagination, use the following values in the array to configure your
     * pagination or offsets.
     *
     * ```php
     * $params = array(
     *     "page"     => 1,  // (int) Page offset to fetch
     *     "per_page" => 10, // (int) Number of results per page
     *     "offset"   => 5,  // (int) Instead of starting at result 0, start counting from result 5
     * );
     * ```
     *
     * @api
     * @param array $params GET parameters passed to with the query to modify the data returned.
     * @since 0.1.0
     * @return PulseUser[]
     */
    public function getSubscribers ($params = array())
    {
        $url = sprintf($this->urlSyntax, parent::apiEndpoint(), $this->id, "subscribers");

        return parent::fetchJsonArrayToObjectArray($url, "PulseUser", $params);
    }

    /**
     * Unsubscribe a person from a pulse
     *
     * @api
     *
     * @param int|PulseUser $user_id The user that will be subscribed
     *
     * @since 0.1.0
     *
     * @throws HttpException Removing a user failed; access the exception for more information.
     */
    public function removeSubscriber ($user_id)
    {
        if ($user_id instanceof PulseUser)
        {
            $user_id = $user_id->getId();
        }

        $url = sprintf("%s/%d/subscribers/%d.json", parent::apiEndpoint(), $this->getId(), $user_id);

        parent::sendDelete($url);
    }

    // ================================================================================================================
    //   Notes functions
    // ================================================================================================================

    /**
     * Create a new note in this project
     *
     * @api
     * @param  string   $title         The title of the note
     * @param  string   $content       The body of the note
     * @param  bool     $owners_only   Set to true if only pulse owners can edit this note.
     * @param  int|null $user_id       The id of the user to be marked as the note's last updater
     * @param  bool     $create_update Indicates whether to create an update on the pulse notifying subscribers on the
     *                                 changes (required user_id to be set).
     * @since  0.1.0
     * @return PulseNote
     */
    public function addNote ($title, $content, $owners_only = false, $user_id = NULL, $create_update = false)
    {
        $url        = sprintf($this->urlSyntax, parent::apiEndpoint(), $this->id, "notes");
        $postParams = array(
            "id"            => $this->id,
            "title"         => $title,
            "content"       => $content,
            "owners_only"   => $owners_only,
            "create_update" => $create_update
        );

        self::setIfNotNullOrEmpty($postParams, "user_id", $user_id);

        if ($create_update && is_null($user_id))
        {
            throw new \InvalidArgumentException("The user_id value must be set if an update is to be created");
        }

        $noteResult = self::sendPost($url, $postParams);

        return (new PulseNote($noteResult));
    }

    /**
     * Return all of the notes belonging to this project
     *
     * @api
     * @since  0.1.0
     * @return PulseNote[]
     */
    public function getNotes ()
    {
        $url = sprintf($this->urlSyntax, parent::apiEndpoint(), $this->id, "notes");

        return parent::fetchJsonArrayToObjectArray($url, "PulseNote");
    }

    // ================================================================================================================
    //   Updates functions
    // ================================================================================================================

    /**
     * Get all of the updates that belong this Pulse
     *
     * @api
     * @since 0.1.0
     * @return PulseUpdate[]
     */
    public function getUpdates ()
    {
        $url = sprintf($this->urlSyntax, parent::apiEndpoint(), $this->id, "updates");

        return parent::fetchJsonArrayToObjectArray($url, "PulseUpdate");
    }

    /**
     * Create an update for the current Pulse
     *
     * @api
     *
     * @param int|PulseUser $user
     * @param string        $text
     * @param null|bool     $announceToAll
     *
     * @since 0.1.0
     */
    public function createUpdate ($user, $text, $announceToAll = NULL)
    {
        PulseUpdate::createUpdate($user, $this->getId(), $text, $announceToAll);
    }

    // ================================================================================================================
    //   Static functions
    // ================================================================================================================

    /**
     * Get all of the pulses that belong to the organization across all boards.
     *
     * To modify the amount of data returned with pagination, use the following values in the array to configure your
     * pagination or offsets.
     *
     * ```php
     * $params = array(
     *     "page"     => 1,          // (int) Page offset to fetch
     *     "per_page" => 10,         // (int) Number of results per page
     *     "offset"   => 5,          // (int) Instead of starting at result 0, start counting from result 5
     *     "order_by_latest" => true // (bool) Order the pulses with the most recent first
     * );
     * ```
     *
     * @api
     * @param array $params GET parameters passed to with the query to modify the data returned.
     * @since 0.1.0
     * @return Pulse[]
     */
    public static function getPulses ($params = array())
    {
        $url = sprintf("%s.json", parent::apiEndpoint());

        return parent::fetchJsonArrayToObjectArray($url, "Pulse", $params);
    }
}
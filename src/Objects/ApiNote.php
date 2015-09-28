<?php

/**
 * This class contains DaPulse Note class
 *
 * @copyright 2015 Vladimir Jimenez
 * @license   https://github.com/allejo/PhpPulse/blob/master/LICENSE.md MIT
 */

namespace allejo\DaPulse\Objects;

/**
 *
 *
 * @since 0.1.0
 */
class ApiNote extends ApiObject
{
    /**
     * The collaboration box type (rich_text, file_list, faq_list).
     *
     * @var string
     */
    protected $type;

    /**
     * The note's id.
     *
     * @var string
     */
    protected $id;

    /**
     * The note's title.
     *
     * @var string
     */
    protected $title;

    /**
     * The note's project_id.
     *
     * @var string
     */
    protected $project_id;

    /**
     * Describes who can edit this note. Can be either 'everyone' or 'owners'.
     *
     * @var string
     */
    protected $permissions;

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


    public function getType ()
    {
        return $this->type;
    }

    public function getId ()
    {
        return $this->id;
    }

    public function getTitle ()
    {
        return $this->title;
    }

    public function getProjectId ()
    {
        return $this->project_id;
    }

    public function getPermissions ()
    {
        return $this->permissions;
    }

    public function getCreatedAt ()
    {
        return $this->created_at;
    }

    public function getUpdatedAt ()
    {
        return $this->updated_at;
    }
}
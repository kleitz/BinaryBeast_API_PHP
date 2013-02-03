<?php

/**
 * Base class for all other object-specific binarybeast service classes (tournaments, teams, etc)
 * 
 * Dual licensed under the MIT and GPL licenses:
 *   http://www.opensource.org/licenses/mit-license.php
 *   http://www.gnu.org/licenses/gpl.html
 * 
 * @version 1.0.0
 * @date 2013-02-02
 * @author Brandon Simmons
 */
class BBModel {

    /**
     * Reference to the main API class
     * @var BinaryBeast
     */
    protected $bb;

    /**
     * Publically accessible result code for the previous api call
     * @var int
     */
    public $result;

    /**
     * If loading failed, stored the result
     * @var string
     */
    protected $last_error;

    /**
     * We're storing values in this array to allow us to handle intercept an attempt to load a value
     * Made public for print_r, var_dump etc
     * @var array
     */
    public $data = array();

    /**
     * Each time a setting is changed, we store the new setting here, so we know
     * which values to send to the API, instead of dumping everything and sending it all
     * @var array
     */
    protected $new_data = array();

    //Should be defined by children classes to let us know which property to use as the unique ID for this instance
    //For example for a tournament, this value should be tourney_id, so we can refer to $this->tourney_id dynamically
    protected $id_property = 'id';
    public $id;

    //Overloaded by children to define default values to use when creating a new object
    protected $default_values = array();

    /**
     * Child classes can define a data extract key to attempt to use
     * @access protected
     */
    protected $data_extraction_key;

    /**
     * Constructor - accepts a reference the BinaryBeats API $bb
     * 
     * @param BinaryBeast $bb       Reference to the main API class
     * @param object      $data     Optionally auto-load this object with values from $data
     */
    function __construct(BinaryBeast &$bb, $data = null) {
        $this->bb = $bb;

        //If provided with data, automatically import the values into this instance
        if(is_object($data) || is_array($data)) {
            //Import the provided values directly into this instance
            $this->import_values($data);

            //Attempt to extract this object's id from the imported data
            $this->extract_id();
        }

        /**
         * If provided with an ID, keep track of it so that we know which
         * ID to load when data is accessed
         * It also saves a copy to $this->id, for standardization and convenience
         */
        else if(is_numeric($data) || is_string($data)) {
            $this->set_id($data);
        }
    }

    /**
     * Intercept attempts to load values from this object
     * 
     * Note: If you update a value, it will return the new value, even if you have
     * not yet saved the object
     * 
     * However you can always call reset() if you would like to revert the
     * accessible values to the original import values
     * 
     * @param string $name
     * @return mixed
     */
    public function &__get($name) {

        //First see if we have the value already in $data, or in new_data
        if(isset($this->new_data[$name]))   return $this->new_data[$name];
        if(isset($this->data[$name]))       return $this->data[$name];

        //Since the value does not exist, see if we've already loaded this object
        if(sizeof($this->data) === 0) {
            $this->load();
            //After loading, try again
            return $this->$name;
        }

        //Last chance is to allow developers to refer to unique id namings as simply "id"
        //IE a developer can retrieve the $tournament->tourney_id by calling $tournament->id
        if($name == 'id') {
            return $this->get_id();
        } 

        //Invalid property requested, return null
        //@todo consider throwing an error instead
        return null;
    }

    /**
     * Intercepts attempts to set property values
     * Very simply stores in $this->new_data
     * 
     * This method actually returns itself to allow chaining, for instance...
     * @example
     * $tournament = $bb->tournament->title = 'asdf';
     * 
     * @param string $name
     * @param mixed $value
     * 
     * @return void
     */
    public function __set($name, $value) {
        //Very simply assign the new value into the new values array
        $this->new_data[$name] = $value;
    }

    /**
     * In case anything has been changed, this method can be used
     * to reset this objects attributes to their original values
     * 
     * Since $this->data isn't changed until sending the data to the API,
     * all we have to do is clear out the new_data array, which is
     * used as a temporary buffer until asking the API to import
     * the changes
     * 
     * @return void
     */
    public function reset() {
        $this->new_data = array();
    }

    /**
     * When values for this object (tournament, team, game, etc), we use this method
     * to assign them to local data
     * 
     * This method is overridden by children classes in order to extract the specific
     * properties containing data, but they then pass it back here
     * to actually cache it locally
     * 
     * Note that $this->data is cast as an array, for consistence access
     * 
     * If you provide a value for $extract, it will attempt to use that value as a key to $extract from within $data first
     * Meaning if $data = ['result' => 500, 'tourney_info' => {'title' => blah'}},
     *  a $key value of 'tourney_info' means {'title' => blah'} will be extracted into $this->data
     *  otherwise the $this->data would end be the entire $data input
     * 
     * Lastly, it resets new_data
     * 
     * @param object    $data
     * @return void
     */
    protected function import_values($data) {
        //Cast it as an array now
        $data = (array)$data;

        //Extract a sub value if requested if the child class defines the key
        if(!is_null($this->data_extraction_key)) {
            //Found it! extract it and cast it as an array again
            if(isset($data[$this->data_extraction_key])) {
                $data = (array)$data[$this->data_extraction_key];
            }
        }

        //Cast as an array for standardization and compatability
        $this->data             = (array)$data;
        $this->new_data         = array();
        //If we're important, that means the last call was successful, so clear any prior errors - done here to avoid having to type it 15 times
        $this->clear_error();
    }

    /**
     * Call the child-defined load service
     * 
     * @param mixed $id     If you did not provide an ID in the instantiation, they can provide one now
     * @param array $args   Allow child classes to define additional paramaters to send to the API (for example the primary key of an object may consist of multiple values)
     * @return boolean - true if result is 200, false otherwise
     */
    public function load($id = null, $args = array()) {
        
        //If no ID was provided, we can't do anything
        if(!is_null($id)) $this->set_id($id);
        else              $id = $this->get_id();

        //No ID to load
        if(is_null($id)) {
            return $this->set_error('No ' . $this->id_property . ' was provided, there is nothing to load!');
        }

        //Determine which sevice to use, return false if the child failed to define one
        $svc = $this->get_service('SERVICE_LOAD');
        if(is_null($svc)) {
            return $this->set_error('Unable to determine which service to request for this object, please contact a BinaryBeast administrator for assistance');
        }

        //GOGOGO!
        $result = $this->bb->call($svc, array_merge(array(
            $this->id_property => $this->{$this->id_property}
        ), $args) );

        //Save the result
        $this->result = $result->result;

        //If successful, import it now
        if($result->result == BinaryBeast::RESULT_SUCCESS) {
            $this->import_values($result);
            return true;
        }

        //OH NOES!
        else {
            return $this->set_error($result);
        }
    }

    /**
     * Sends the values in this object to the API, to either update or create the tournament, team, etc
     * 
     * This method returns the new or existing ID, or false on failure
     * 
     * @return string|int   false if the call failed
     */
    public function save() {

        //Initialize some arguments for the API
        $svc    = null;
        $args   = array();

        //Determine the id to update, if there is one
        $id = $this->get_id();

        //Update
        if( !is_null($id) ) {

            //Nothing has changed!
            if(sizeof($this->new_data) === 0) {
                return $this->set_error('You have not changed any values to submit!');
            }

            //GOGOGO! determine the service name, and save the id
            $args = $this->new_data;
            $args[$this->id_property] = $id;
            $svc = $this->get_service('SERVICE_UPDATE');
        }

        //Create - merge the arguments with the default / newly set values
        else {
            $args = array_merge($this->default_values, $this->new_data);
            $svc = $this->get_service('SERVICE_CREATE');
        }

        //GOGOGO
        $result = $this->bb->call($svc, $args);

        //Store the result code
        $this->result = $result->result;

        /*
         * Saved successfully - reset some local values and return true
         */
        if($result->result == BinaryBeast::RESULT_SUCCESS) {

            //Delete any prior error messages
            $this->clear_error();

            //If this was a new object, try to extract the $id directly
            if(is_null($id)) {
                if(isset($result->{$this->id_property})) {                    
                    $id = $result->{$this->id_property};
                    $this->set_id($id);
                }
            }
            /*
             * For existing instances, remove the id from the arguments list,
             * otherwise it'll end up in $this->data, which is simply unecessary and messy for var_dumps
             */
            else {
                unset($args[$this->id_property]);
            }

            //Merge the new values into $this->data
            $args = array_merge($this->data, $args);
            $this->import_values($args);

            //Updated successfully! return the $id
            return $id;
        }

        /*
         * Oh noes!
         * Save the response as the local error, and return false
         */
        else {
            return $this->set_error($result);
        }
    }

    /**
     * Delete the current object from BinaryBeast!!
     * @return boolean
     */
    public function delete() {
        //Determine the service name and arguments
        $svc = $this->get_service('SERVICE_DELETE');
        $args = array(
            $this->id_property => $this->{$this->id_property}
        );

        //GOGOGO!!!
        $result = $this->bb->call($svc, $args);

        //Save the result code locally
        $this->result = $result->result;

        //DELETED!!!
        if($result->result == BinaryBeast::RESULT_SUCCESS) {
            //Reset all local values and errors
            $this->set_id(null);
            $this->data = array();
            $this->new_data = array();
            $this->clear_error();
            return true;
        }

        //Most common error would likely be an authentication error
        else if($result->result == BinaryBeast::RESULT_AUTH) {
            return $this->set_error('You do not have permission to delete this object!');
        }

        //Other error, store it and return false
        else {
            return $this->set_error($result);
        }
    }

    /**
     * Returns the last error (if it exists)
     * @return mixed
     */
    public function error() {
        return $this->last_error;
    }

    /**
     * Store an error into $this->error, developers can refer to it
     * as $tournament|$match|etc->error()
     * 
     * In order to standardize error values, it accepts arrays, or strings
     * but if you provide a string, it is converted into array('error_message'=>$in)
     * 
     * @param array|string $error
     * @return false
     */
    protected function set_error($error) {

        //Either save as-is, or convert into an array (for standardization)
        $this->last_error = is_string($error)
            ? array('error_message' => $error)
            : $error;

        //Allows return this directly to return false, saves a line of code - don't have to set_error then return false
        return false;
    }

    /**
     * Remove any existing errors
     * @return void
     */
    protected function clear_error() {
        $this->last_error = null;
    }

    /**
     * Get the service that the child class supposedly defines
     * 
     * @param string $svc
     * 
     * @return string
     */
    private function get_service($svc) {
        return constant(get_called_class() . '::' . $svc);
    }

    /**
     * Save the unique id of this instance, taking into consideration
     * children clases definining the property name, (ie tournament -> tourney_team_id)
     * 
     * It also saves a reference in this->id for developers can easily refer to $obj->id,
     *  regardless of the object type
     * 
     * @param int|string $id
     * @return void
     */
    protected function set_id($id) {
        $this->{$this->id_property} = $id;
        if($this->id_property !== 'id') $this->id = &$this->{$this->id_property};
    }

    /**
     * Attempt to extract the id value from $this->data, and assign it to $ths->{$id_property}
     * 
     * @return boolean - true if found 
     */
    protected function extract_id() {
        if(isset($this->data[$this->id_property])) {
            $this->set_id($this->data[$this->id_property]);
            return true;
        }

        //Either not instantiated or wasn't present in $data
        return false;
    }

    /**
     * Retrive the unique id of this instance, taking into consideration
     * children clases definining the property name, (ie tournament -> tourney_team_id)
     * 
     * @return mixed
     */
    protected function get_id() {
        return $this->{$this->id_property};
    }

    /**
     * Iterates through a list of returned objects form the API, and "casts" them as
     * modal classes
     * for example, $bb->tournament->list_my() returns an array, each element being an instance of BBTournament,
     * so you could for example, delete all of your 'SC2' Tournaments like this (be careful, this can be dangerous and irreversable!)
     * 
     * 
     *  $tournies = $bb->tournament->list_my('SC2');
     *  foreach($tournies as &$tournament) {
     *      $tournament->delete();
     *  }
     * 
     * @param array $list
     * @return array<BBTournament> $class
     */
    protected function wrap_list($list, $class = null) {
        //Determine which class to instantiate if not provided
        if(is_null($class)) {
            $class = get_called_class();
        }

        //Add instantiated modals of each element into a new output array
        $out = array();
        foreach($list as $object) {
            $out[] = new $class($this->bb, $object);
        }

        return $out;
    }
}

?>
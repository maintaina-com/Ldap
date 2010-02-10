<?php
/**
 * File containing the Horde_Ldap interface class.
 *
 * PHP version 5
 *
 * @category  Net
 * @package   Horde_Ldap
 * @author    Tarjej Huse <tarjei@bergfald.no>
 * @author    Jan Wagner <wagner@netsols.de>
 * @author    Del <del@babel.com.au>
 * @author    Benedikt Hallinger <beni@php.net>
 * @author    Ben Klang <ben@alkaloid.net>
 * @author    Chuck Hagenbuch <chuck@horde.org>
 * @copyright 2009 The Horde Project
 * @copyright 2003-2007 Tarjej Huse, Jan Wagner, Del Elson, Benedikt Hallinger
 * @license   http://www.gnu.org/licenses/lgpl-3.0.txt LGPLv3
 */

/**
 * Package includes.
 */
#require_once 'Net/Ldap/RootDSE.php';
#require_once 'Net/Ldap/Schema.php';
#require_once 'Net/Ldap/Entry.php';
#require_once 'Net/Ldap/Search.php';
#require_once 'Net/Ldap/Util.php';
#require_once 'Net/Ldap/Filter.php';
#require_once 'Net/Ldap/LDIF.php';
#require_once 'Net/Ldap/SchemaCache.interface.php';
#require_once 'Net/Ldap/SimpleFileSchemaCache.php';

class Horde_Ldap
{

    /**
     *  Error constants for errors that are not LDAP errors.
     */
    const _ERROR = 1000;
    
    /**
     * Library Version
     */
    const VERSION = '0.1.0';

    /**
     * Class configuration array
     *
     * host     = the ldap host to connect to
     *            (may be an array of several hosts to try)
     * port     = the server port
     * version  = ldap version (defaults to v 3)
     * starttls = when set, ldap_start_tls() is run after connecting.
     * bindpw   = no explanation needed
     * binddn   = the DN to bind as.
     * basedn   = ldap base
     * options  = hash of ldap options to set (opt => val)
     * filter   = default search filter
     * scope    = default search scope
     *
     * Newly added in 2.0.0RC4, for auto-reconnect:
     * auto_reconnect  = if set to true then the class will automatically
     *                   attempt to reconnect to the LDAP server in certain
     *                   failure conditionswhen attempting a search, or other
     *                   LDAP operation.  Defaults to false.  Note that if you
     *                   set this to true, calls to search() may block
     *                   indefinitely if there is a catastrophic server failure.
     * min_backoff     = minimum reconnection delay period (in seconds).
     * current_backoff = initial reconnection delay period (in seconds).
     * max_backoff     = maximum reconnection delay period (in seconds).
     *
     * @access protected
     * @var array
     */
    protected $_config = array('host'            => 'localhost',
                               'port'            => 389,
                               'version'         => 3,
                               'starttls'        => false,
                               'binddn'          => '',
                               'bindpw'          => '',
                               'basedn'          => '',
                               'options'         => array(),
                               'filter'          => '(objectClass=*)',
                               'scope'           => 'sub',
                               'auto_reconnect'  => false,
                               'min_backoff'     => 1,
                               'current_backoff' => 1,
                               'max_backoff'     => 32);

    /**
     * List of hosts we try to establish a connection to
     *
     * @access protected
     * @var array
     */
    protected $_host_list = array();

    /**
     * List of hosts that are known to be down.
     *
     * @access protected
     * @var array
     */
    protected $_down_host_list = array();

    /**
     * LDAP resource link.
     *
     * @access protected
     * @var resource
     */
    protected $_link = false;

    /**
     * Horde_Ldap_Schema object
     *
     * This gets set and returned by {@link schema()}
     *
     * @access protected
     * @var object Horde_Ldap_Schema
     */
    protected $_schema = null;

    /**
     * Schema cacher function callback
     *
     * @see registerSchemaCache()
     * @var string
     */
    protected $_schema_cache = null;

    /**
     * Cache for attribute encoding checks
     *
     * @access protected
     * @var array Hash with attribute names as key and boolean value
     *            to determine whether they should be utf8 encoded or not.
     */
    protected $_schemaAttrs = array();

    /**
     * Cache for rootDSE objects
     *
     * Hash with requested rootDSE attr names as key and rootDSE object as value
     *
     * Since the RootDSE object itself may request a rootDSE object,
     * {@link rootDse()} caches successful requests.
     * Internally, Horde_Ldap needs several lookups to this object, so
     * caching increases performance significally.
     *
     * @access protected
     * @var array
     */
    protected $_rootDSE_cache = array();

    /**
     * Returns the Horde_Ldap Release version, may be called statically
     *
     * @static
     * @return string Horde_Ldap version
     */
    public static function getVersion()
    {
        return Horde_Ldap::VERSION;
    }

    /**
     * Configure Horde_Ldap, connect and bind
     *
     * Use this method as starting point of using Horde_Ldap
     * to establish a connection to your LDAP server.
     *
     * Static function that returns either an error object or the new Horde_Ldap
     * object. Something like a factory. Takes a config array with the needed
     * parameters.
     *
     * @param array $config  Configuration array
     *
     * @access public
     * @return Horde_Ldap  Horde_Ldap object
     */
    public static function &connect($config = array())
    {
        $ldap_check = self::checkLDAPExtension();

        $obj = new Horde_Ldap($config);

        // todo? better errorhandling for setConfig()?

        // connect and bind with credentials in config
        $obj->bind();

        return $obj;
    }

    /**
     * Horde_Ldap constructor
     *
     * Sets the config array
     *
     * Please note that the usual way of getting Horde_Ldap to work is
     * to call something like:
     * <code>$ldap = Horde_Ldap::connect($ldap_config);</code>
     *
     * @param array $config Configuration array
     *
     * @access protected
     * @return void
     * @see $_config
     */
    public function __construct($config = array())
    {
        $this->setConfig($config);
    }

    /**
     * Sets the internal configuration array
     *
     * @param array $config Configuration array
     *
     * @access protected
     * @return void
     */
    protected function setConfig($config)
    {
        //
        // Parameter check -- probably should raise an error here if config
        // is not an array.
        //
        if (! is_array($config)) {
            return;
        }

        foreach ($config as $k => $v) {
            if (isset($this->_config[$k])) {
                $this->_config[$k] = $v;
            } else {
                // map old (Net_Ldap) parms to new ones
                switch($k) {
                case "dn":
                    $this->_config["binddn"] = $v;
                    break;
                case "password":
                    $this->_config["bindpw"] = $v;
                    break;
                case "tls":
                    $this->_config["starttls"] = $v;
                    break;
                case "base":
                    $this->_config["basedn"] = $v;
                    break;
                }
            }
        }

        //
        // Ensure the host list is an array.
        //
        if (is_array($this->_config['host'])) {
            $this->_host_list = $this->_config['host'];
        } else {
            if (strlen($this->_config['host']) > 0) {
                $this->_host_list = array($this->_config['host']);
            } else {
                $this->_host_list = array();
                // ^ this will cause an error in performConnect(),
                // so the user is notified about the failure
            }
        }

        //
        // Reset the down host list, which seems like a sensible thing to do
        // if the config is being reset for some reason.
        //
        $this->_down_host_list = array();
    }

    /**
     * Bind or rebind to the ldap-server
     *
     * This function binds with the given dn and password to the server. In case
     * no connection has been made yet, it will be started and startTLS issued
     * if appropiate.
     *
     * The internal bind configuration is not being updated, so if you call
     * bind() without parameters, you can rebind with the credentials
     * provided at first connecting to the server.
     *
     * @param string $dn       Distinguished name for binding
     * @param string $password Password for binding
     *
     * @access public
     * @return true  true on success
     */
    public function bind($dn = null, $password = null)
    {
        // fetch current bind credentials
        if (is_null($dn)) {
            $dn = $this->_config["binddn"];
        }
        if (is_null($password)) {
            $password = $this->_config["bindpw"];
        }

        // Connect first, if we haven't so far.
        // This will also bind us to the server.
        if ($this->_link === false) {
            // store old credentials so we can revert them later
            // then overwrite config with new bind credentials
            $olddn = $this->_config["binddn"];
            $oldpw = $this->_config["bindpw"];

            // overwrite bind credentials in config
            // so performConnect() knows about them
            $this->_config["binddn"] = $dn;
            $this->_config["bindpw"] = $password;

            // try to connect with provided credentials
            $msg = $this->performConnect();

            // reset to previous config
            $this->_config["binddn"] = $olddn;
            $this->_config["bindpw"] = $oldpw;

            // see if bind worked
            if (self::isError($msg)) {
                return $msg;
            }
        } else {
            // do the requested bind as we are
            // asked to bind manually
            if (is_null($dn)) {
                // anonymous bind
                $msg = @ldap_bind($this->_link);
            } else {
                // privileged bind
                $msg = @ldap_bind($this->_link, $dn, $password);
            }
            if (false === $msg) {
                throw new Horde_Ldap_Exception("Bind failed: " .
                                               @ldap_error($this->_link),
                                               @ldap_errno($this->_link));
            }
        }
        return true;
    }

    /**
     * Connect to the LDAP server
     *
     * This function connects to the LDAP server specified in
     * the configuration, binds and set up the LDAP protocol as needed.
     *
     * @access protected
     * @return true  true on success
     */
    protected function performConnect()
    {
        // Note: Connecting is briefly described in RFC1777.
        // Basicly it works like this:
        //  1. set up TCP connection
        //  2. secure that connection if neccessary
        //  3a. setLDAPVersion to tell server which version we want to speak
        //  3b. perform bind
        //  3c. setLDAPVersion to tell server which version we want to speak
        //      together with a test for supported versions
        //  4. set additional protocol options

        // Return true if we are already connected.
        if ($this->_link !== false) {
            return true;
        }

        // Connnect to the LDAP server if we are not connected.  Note that
        // with some LDAP clients, ldapperformConnect returns a link value even
        // if no connection is made.  We need to do at least one anonymous
        // bind to ensure that a connection is actually valid.
        //
        // Ref: http://www.php.net/manual/en/function.ldap-connect.php

        // Default error message in case all connection attempts
        // fail but no message is set
        $current_error = new Horde_Ldap_Exception('Unknown connection error');

        // Catch empty $_host_list arrays.
        if (!is_array($this->_host_list) || count($this->_host_list) == 0) {
            throw new Horde_Ldap_Exception('No servers configured');
        }

        // Cycle through the host list.
        foreach ($this->_host_list as $host) {

            // Ensure we have a valid string for host name
            if (is_array($host)) {
                $current_error = new Horde_Ldap_Exception('No Servers configured');
                continue;
            }

            // Skip this host if it is known to be down.
            if (in_array($host, $this->_down_host_list)) {
                continue;
            }

            // Record the host that we are actually connecting to in case
            // we need it later.
            $this->_config['host'] = $host;

            // Attempt a connection.
            $this->_link = @ldap_connect($host, $this->_config['port']);
            if (false === $this->_link) {
                $current_error = new Horde_Ldap_Exception('Could not connect to ' .  $host . ':' . $this->_config['port']);
                $this->_down_host_list[] = $host;
                continue;
            }

            // If we're supposed to use TLS, do so before we try to bind,
            // as some strict servers only allow binding via secure connections
            if ($this->_config["starttls"] === true) {
                if (self::isError($msg = $this->startTLS())) {
                    $current_error           = $msg;
                    $this->_link             = false;
                    $this->_down_host_list[] = $host;
                    continue;
                }
            }

            // Try to set the configured LDAP version on the connection if LDAP
            // server needs that before binding (eg OpenLDAP).
            // This could be necessary since rfc-1777 states that the protocol
            // version has to be set at the bind request.
            // We use force here which means that the test in the rootDSE is
            // skipped; this is neccessary, because some strict LDAP servers
            // only allow to // read the LDAP rootDSE (which tells us the
            // supported protocol versions) with authenticated clients.
            // This may fail in which case we try again after binding.
            // In this case, most probably the bind() or setLDAPVersion()-call
            // below will also fail, providing error messages.
            $version_set = false;
            $this->setLDAPVersion(0, true);

            // Attempt to bind to the server. If we have credentials configured,
            // we try to use them, otherwise its an anonymous bind.
            // As stated by RFC-1777, the bind request should be the first
            // operation to be performed after the connection is established.
            // This may give an protocol error if the server does not support
            // V2 binds and the above call to setLDAPVersion() failed.
            // In case the above call failed, we try an V2 bind here and set the
            // version afterwards (with checking to the rootDSE).
            try {
                $msg = $this->bind();
            } catch (Exception $e) {
                // The bind failed, discard link and save error msg.
                // Then record the host as down and try next one
                if ($e->getCode() == 0x02 && !$version_set) {
                    // provide a finer grained error message
                    // if protocol error arises because of invalid version
                    $e = new Horde_Ldap_Exception($e->getMessage().
                        " (could not set LDAP protocol version to ".
                        $this->_config['version'].")",
                        $e->getCode());
                }
                $this->_link             = false;
                $current_error           = $e;
                $this->_down_host_list[] = $host;
                continue;
            }

            // Set desired LDAP version if not successfully set before.
            // Here, a check against the rootDSE is performed, so we get a
            // error message if the server does not support the version.
            // The rootDSE entry should tell us which LDAP versions are
            // supported. However, some strict LDAP servers only allow
            // bound suers to read the rootDSE.
            if (!$version_set) {
                try {
                    $this->setLDAPVersion();
                } catch (Exception $e) {
                    $current_error           = $e;
                    $this->_link             = false;
                    $this->_down_host_list[] = $host;
                    continue;
                }
            }

            // Set LDAP parameters, now we know we have a valid connection.
            if (isset($this->_config['options']) &&
                is_array($this->_config['options']) &&
                count($this->_config['options'])) {
                foreach ($this->_config['options'] as $opt => $val) {
                    try {
                        $this->setOption($opt, $val);
                    } catch (Exception $e) {
                        $current_error           = $e;
                        $this->_link             = false;
                        $this->_down_host_list[] = $host;
                        continue 2;
                    }
                }
            }

            // At this stage we have connected, bound, and set up options,
            // so we have a known good LDAP server.  Time to go home.
            return true;
        }


        // All connection attempts have failed, return the last error.
        throw $current_error;
    }

    /**
     * Reconnect to the LDAP server.
     *
     * In case the connection to the LDAP
     * service has dropped out for some reason, this function will reconnect,
     * and re-bind if a bind has been attempted in the past.  It is probably
     * most useful when the server list provided to the new() or connect()
     * function is an array rather than a single host name, because in that
     * case it will be able to connect to a failover or secondary server in
     * case the primary server goes down.
     *
     * This doesn't return anything, it just tries to re-establish
     * the current connection.  It will sleep for the current backoff
     * period (seconds) before attempting the connect, and if the
     * connection fails it will double the backoff period, but not
     * try again.  If you want to ensure a reconnection during a
     * transient period of server downtime then you need to call this
     * function in a loop.
     *
     * @access protected
     * @return true  true on success
     */
    protected function performReconnect()
    {

        // Return true if we are already connected.
        if ($this->_link !== false) {
            return true;
        }

        // Default error message in case all connection attempts
        // fail but no message is set
        $current_error = new Horde_Ldap_Exception('Unknown connection error');

        // Sleep for a backoff period in seconds.
        sleep($this->_config['current_backoff']);

        // Retry all available connections.
        $this->_down_host_list = array();
        $msg = $this->performConnect();

        // Bail out if that fails.
        if (self::isError($msg)) {
            $this->_config['current_backoff'] =
               $this->_config['current_backoff'] * 2;
            if ($this->_config['current_backoff'] > $this->_config['max_backoff']) {
                $this->_config['current_backoff'] = $this->_config['max_backoff'];
            }
            return $msg;
        }

        // Now we should be able to safely (re-)bind.
        try {
            $this->bind();
        } catch (Exception $e) {
            $this->_config['current_backoff'] = $this->_config['current_backoff'] * 2;
            if ($this->_config['current_backoff'] > $this->_config['max_backoff']) {
                $this->_config['current_backoff'] = $this->_config['max_backoff'];
            }

            // _config['host'] should have had the last connected host stored in it
            // by performConnect().  Since we are unable to bind to that host we can safely
            // assume that it is down or has some other problem.
            $this->_down_host_list[] = $this->_config['host'];
            throw e;
        }

        // At this stage we have connected, bound, and set up options,
        // so we have a known good LDAP server. Time to go home.
        $this->_config['current_backoff'] = $this->_config['min_backoff'];
        return true;
    }

    /**
     * Starts an encrypted session
     *
     * @access public
     * @return true  true on success
     */
    public function startTLS()
    {
        // Test to see if the server supports TLS first.
        // This is done via testing the extensions offered by the server.
        // The OID 1.3.6.1.4.1.1466.20037 tells whether TLS is supported.
        try {
            $rootDSE = $this->rootDse();
        } catch (Exception $e) {
            throw new Horde_Ldap_Exception("Unable to fetch rootDSE entry ".
            "to see if TLS is supoported: ".$e->getMessage(), $e->getCode());
        }

        try {
            $supported_extensions = $rootDSE->getValue('supportedExtension');
        } catch (Exception $e) {
            throw new Horde_Ldap_Exception("Unable to fetch rootDSE attribute 'supportedExtension' ".
            "to see if TLS is supoported: ".$e->getMessage(), $e->getCode());
        }

        if (in_array('1.3.6.1.4.1.1466.20037', $supported_extensions)) {
            if (false === @ldap_start_tls($this->_link)) {
                throw new Horde_Ldap_Exception("TLS not started: " .
                                               @ldap_error($this->_link),
                                               @ldap_errno($this->_link));
            }
            return true;
        } else {
            throw new Horde_Ldap_Exception("Server reports that it does not support TLS");
        }
    }

    /**
     * Close LDAP connection.
     *
     * Closes the connection. Use this when the session is over.
     *
     * @return void
     */
    public function done()
    {
        $this->_Horde_Ldap();
    }

    /**
     * Alias for {@link done()}
     *
     * @return void
     * @see done()
     */
    public function disconnect()
    {
        $this->done();
    }

    /**
     * Destructor
     *
     * @access protected
     */
    private function _Horde_Ldap()
    {
        @ldap_close($this->_link);
    }

    /**
     * Add a new entryobject to a directory.
     *
     * Use add to add a new Horde_Ldap_Entry object to the directory.
     * This also links the entry to the connection used for the add,
     * if it was a fresh entry ({@link HordeLdap_Entry::createFresh()})
     *
     * @param Horde_Ldap_Entry &$entry HordeLdap_Entry
     *
     * @return true  Horde_Ldap_Error object or true
     */
    public function add(&$entry)
    {
        if (!$entry instanceof Horde_Ldap_Entry) {
            throw new Horde_Ldap_Exception('Parameter to Horde_Ldap::add() must be a Horde_Ldap_Entry object.');
        }

        // Continue attempting the add operation in a loop until we
        // get a success, a definitive failure, or the world ends.
        $foo = 0;
        while (true) {
            $link = $this->getLink();

            if ($link === false) {
                // We do not have a successful connection yet.  The call to
                // getLink() would have kept trying if we wanted one.  Go
                // home now.
                throw new Horde_Ldap_Exception("Could not add entry " . $entry->dn() .
                       " no valid LDAP connection could be found.");
            }

            if (@ldap_add($link, $entry->dn(), $entry->getValues())) {
                // entry successfully added, we should update its $ldap reference
                // in case it is not set so far (fresh entry)
                if (!$entry->getLDAP() instanceof Horde_Ldap) {
                    $entry->setLDAP($this);
                }
                // store, that the entry is present inside the directory
                $entry->markAsNew(false);
                return true;
            } else {
                // We have a failure.  What type?  We may be able to reconnect
                // and try again.
                $error_code = @ldap_errno($link);
                $error_name = $this->errorMessage($error_code);

                if (($error_name === 'LDAP_OPERATIONS_ERROR') &&
                    ($this->_config['auto_reconnect'])) {

                    // The server has become disconnected before trying the
                    // operation.  We should try again, possibly with a different
                    // server.
                    $this->_link = false;
                    $this->performReconnect();
                } else {
                    // Errors other than the above catched are just passed
                    // back to the user so he may react upon them.
                    throw new Horde_Ldap_Exception("Could not add entry " . $entry->dn() . " " .
                                                   $error_name,
                                                   $error_code);
                }
            }
        }
    }

    /**
     * Delete an entry from the directory
     *
     * The object may either be a string representing the dn or a Horde_Ldap_Entry
     * object. When the boolean paramter recursive is set, all subentries of the
     * entry will be deleted as well.
     *
     * @param string|Horde_Ldap_Entry $dn        DN-string or Horde_Ldap_Entry
     * @param boolean                $recursive Should we delete all children recursive as well?
     *
     * @access public
     * @return true  true on success
     */
    public function delete($dn, $recursive = false)
    {
        if ($dn instanceof Horde_Ldap_Entry) {
             $dn = $dn->dn();
        }
        if (false === is_string($dn)) {
            throw new Horde_Ldap_Exception("Parameter is not a string nor an entry object!");
        }
        // Recursive delete searches for children and calls delete for them
        if ($recursive) {
            $result = @ldap_list($this->_link, $dn, '(objectClass=*)', array(null), 0, 0);
            if (@ldap_count_entries($this->_link, $result)) {
                $subentry = @ldap_first_entry($this->_link, $result);
                $this->delete(@ldap_get_dn($this->_link, $subentry), true);
                while ($subentry = @ldap_next_entry($this->_link, $subentry)) {
                    $this->delete(@ldap_get_dn($this->_link, $subentry), true);
                }
            }
        }

        // Continue attempting the delete operation in a loop until we
        // get a success, a definitive failure, or the world ends.
        while (true) {
            $link = $this->getLink();

            if ($link === false) {
                // We do not have a successful connection yet.  The call to
                // getLink() would have kept trying if we wanted one.  Go
                // home now.
                throw new Horde_Ldap_Exception("Could not add entry " . $dn .
                       " no valid LDAP connection could be found.");
            }

            if (@ldap_delete($link, $dn)) {
                // entry successfully deleted.
                return true;
            } else {
                // We have a failure.  What type?
                // We may be able to reconnect and try again.
                $error_code = @ldap_errno($link);
                $error_name = $this->errorMessage($error_code);

                if (($this->errorMessage($error_code) === 'LDAP_OPERATIONS_ERROR') &&
                    ($this->_config['auto_reconnect'])) {
                    // The server has become disconnected before trying the
                    // operation.  We should try again, possibly with a
                    // different server.
                    $this->_link = false;
                    $this->performReconnect();

                } elseif ($error_code == 66) {
                    // Subentries present, server refused to delete.
                    // Deleting subentries is the clients responsibility, but
                    // since the user may not know of the subentries, we do not
                    // force that here but instead notify the developer so he
                    // may take actions himself.
                    throw new Horde_Ldap_Exception("Could not delete entry $dn because of subentries. Use the recursive parameter to delete them.");

                } else {
                    // Errors other than the above catched are just passed
                    // back to the user so he may react upon them.
                    throw new Horde_Ldap_Exception("Could not delete entry " . $dn . " " .
                                                   $error_name,
                                                   $error_code);
                }
            }
        }
    }

    /**
     * Modify an ldapentry directly on the server
     *
     * This one takes the DN or a Horde_Ldap_Entry object and an array of actions.
     * This array should be something like this:
     *
     * array('add' => array('attribute1' => array('val1', 'val2'),
     *                      'attribute2' => array('val1')),
     *       'delete' => array('attribute1'),
     *       'replace' => array('attribute1' => array('val1')),
     *       'changes' => array('add' => ...,
     *                          'replace' => ...,
     *                          'delete' => array('attribute1', 'attribute2' => array('val1')))
     *
     * The changes array is there so the order of operations can be influenced
     * (the operations are done in order of appearance).
     * The order of execution is as following:
     *   1. adds from 'add' array
     *   2. deletes from 'delete' array
     *   3. replaces from 'replace' array
     *   4. changes (add, replace, delete) in order of appearance
     * All subarrays (add, replace, delete, changes) may be given at the same time.
     *
     * The function calls the corresponding functions of an Horde_Ldap_Entry
     * object. A detailed description of array structures can be found there.
     *
     * Unlike the modification methods provided by the Horde_Ldap_Entry object,
     * this method will instantly carry out an update() after each operation,
     * thus modifying "directly" on the server.
     *
     * @param string|Horde_Ldap_Entry $entry DN-string or Horde_Ldap_Entry
     * @param array                  $parms Array of changes
     *
     * @access public
     * @return true true on success
     */
    public function modify($entry, $parms = array())
    {
        if (is_string($entry)) {
            $entry = $this->getEntry($entry);
                   }
        if (!$entry instanceof Horde_Ldap_Entry) {
            throw new Horde_Ldap_Exception("Parameter is not a string nor an entry object!");
        }

        // Perform changes mentioned separately
        foreach (array('add', 'delete', 'replace') as $action) {
            if (isset($parms[$action])) {
                $msg = $entry->$action($parms[$action]);

                $entry->setLDAP($this);

                // Because the @ldap functions are called inside Horde_Ldap_Entry::update(),
                // we have to trap the error codes issued from that if we want to support
                // reconnection.
                while (true) {
                    try {
                        $entry->update();
                        break;
                    } catch (Exception $e) {
                        // We have a failure.  What type?  We may be able to reconnect
                        // and try again.
                        $error_code = $e->getCode();
                        $error_name = $this->errorMessage($error_code);

                        if (($this->errorMessage($error_code) === 'LDAP_OPERATIONS_ERROR') &&
                            ($this->_config['auto_reconnect'])) {

                            // The server has become disconnected before trying the
                            // operation.  We should try again, possibly with a different
                            // server.
                            $this->_link = false;
                            $this->performReconnect();

                        } else {

                            // Errors other than the above catched are just passed
                            // back to the user so he may react upon them.
                            throw new Horde_Ldap_Exception("Could not modify entry: ".$e->getMessage());
                        }
                    }
                }
            }
        }

        // perform combined changes in 'changes' array
        if (isset($parms['changes']) && is_array($parms['changes'])) {
            foreach ($parms['changes'] as $action => $value) {

                // Because the @ldap functions are called inside Horde_Ldap_Entry::update,
                // we have to trap the error codes issued from that if we want to support
                // reconnection.
                while (true) {
                    try {
                        $this->modify($entry, array($action => $value));
                        break;
                    } catch (Exception $e) {
                        // We have a failure.  What type?  We may be able to reconnect
                        // and try again.
                        $error_code = $e->getCode();
                        $error_name = $this->errorMessage($error_code);

                        if (($this->errorMessage($error_code) === 'LDAP_OPERATIONS_ERROR') &&
                            ($this->_config['auto_reconnect'])) {

                            // The server has become disconnected before trying the
                            // operation.  We should try again, possibly with a different
                            // server.
                            $this->_link = false;
                            $this->performReconnect();

                        } else {
                            // Errors other than the above catched are just passed
                            // back to the user so he may react upon them.
                            return $msg;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Run a ldap search query
     *
     * Search is used to query the ldap-database.
     * $base and $filter may be ommitted. The one from config will
     * then be used. $base is either a DN-string or an Horde_Ldap_Entry
     * object in which case its DN willb e used.
     *
     * Params may contain:
     *
     * scope: The scope which will be used for searching
     *        base - Just one entry
     *        sub  - The whole tree
     *        one  - Immediately below $base
     * sizelimit: Limit the number of entries returned (default: 0 = unlimited),
     * timelimit: Limit the time spent for searching (default: 0 = unlimited),
     * attrsonly: If true, the search will only return the attribute names,
     * attributes: Array of attribute names, which the entry should contain.
     *             It is good practice to limit this to just the ones you need.
     * [NOT IMPLEMENTED]
     * deref: By default aliases are dereferenced to locate the base object for the search, but not when
     *        searching subordinates of the base object. This may be changed by specifying one of the
     *        following values:
     *
     *        never  - Do not dereference aliases in searching or in locating the base object of the search.
     *        search - Dereference aliases in subordinates of the base object in searching, but not in
     *                locating the base object of the search.
     *        find
     *        always
     *
     * Please note, that you cannot override server side limitations to sizelimit
     * and timelimit: You can always only lower a given limit.
     *
     * @param string|Horde_Ldap_Entry  $base   LDAP searchbase
     * @param string|Horde_Ldap_Filter $filter LDAP search filter or a Horde_Ldap_Filter object
     * @param array                    $params Array of options
     *
     * @access public
     * @return Horde_Ldap_Search  Horde_Ldap_Search object
     * @todo implement search controls (sorting etc)
     */
    public function search($base = null, $filter = null, $params = array())
    {
        if (is_null($base)) {
            $base = $this->_config['basedn'];
        }
        if ($base instanceof Horde_Ldap_Entry) {
            $base = $base->dn(); // fetch DN of entry, making searchbase relative to the entry
        }
        if (is_null($filter)) {
            $filter = $this->_config['filter'];
        }
        if ($filter instanceof Horde_Ldap_Filter) {
            $filter = $filter->asString(); // convert Horde_Ldap_Filter to string representation
        }

        /* setting searchparameters  */
        (isset($params['sizelimit']))  ? $sizelimit  = $params['sizelimit']  : $sizelimit = 0;
        (isset($params['timelimit']))  ? $timelimit  = $params['timelimit']  : $timelimit = 0;
        (isset($params['attrsonly']))  ? $attrsonly  = $params['attrsonly']  : $attrsonly = 0;
        (isset($params['attributes'])) ? $attributes = $params['attributes'] : $attributes = array();

        // Ensure $attributes to be an array in case only one
        // attribute name was given as string
        if (!is_array($attributes)) {
            $attributes = array($attributes);
        }

        // reorganize the $attributes array index keys
        // sometimes there are problems with not consecutive indexes
        $attributes = array_values($attributes);

        // scoping makes searches faster!
        $scope = (isset($params['scope']) ? $params['scope'] : $this->_config['scope']);

        switch ($scope) {
        case 'one':
            $search_function = 'ldap_list';
            break;
        case 'base':
            $search_function = 'ldap_read';
            break;
        default:
            $search_function = 'ldap_search';
        }

        // Continue attempting the search operation until we get a success
        // or a definitive failure.
        while (true) {
            $link = $this->getLink();
            $search = @call_user_func($search_function,
                                      $link,
                                      $base,
                                      $filter,
                                      $attributes,
                                      $attrsonly,
                                      $sizelimit,
                                      $timelimit);

            if ($err = @ldap_errno($link)) {
                if ($err == 32) {
                    // Errorcode 32 = no such object, i.e. a nullresult.
                    return $obj = new Horde_Ldap_Search ($search, $this, $attributes);
                } elseif ($err == 4) {
                    // Errorcode 4 = sizelimit exeeded.
                    return $obj = new Horde_Ldap_Search ($search, $this, $attributes);
                } elseif ($err == 87) {
                    // bad search filter
                    throw new Horde_Ldap_Exception($this->errorMessage($err) . "($filter)", $err);
                } elseif (($err == 1) && ($this->_config['auto_reconnect'])) {
                    // Errorcode 1 = LDAP_OPERATIONS_ERROR but we can try a reconnect.
                    $this->_link = false;
                    $this->performReconnect();
                } else {
                    $msg = "\nParameters:\nBase: $base\nFilter: $filter\nScope: $scope";
                    throw new Horde_Ldap_Exception($this->errorMessage($err) . $msg, $err);
                }
            } else {
                return $obj = new Horde_Ldap_Search($search, $this, $attributes);
            }
        }
    }

    /**
     * Set an LDAP option
     *
     * @param string $option Option to set
     * @param mixed  $value  Value to set Option to
     *
     * @access public
     * @return true  true on success
     */
    public function setOption($option, $value)
    {
        if ($this->_link) {
            if (defined($option)) {
                if (@ldap_set_option($this->_link, constant($option), $value)) {
                    return true;
                } else {
                    $err = @ldap_errno($this->_link);
                    if ($err) {
                        $msg = @ldap_err2str($err);
                    } else {
                        $err = Horde_Ldap::_ERROR;
                        $msg = $this->errorMessage($err);
                    }
                    throw new Horde_Ldap_Exception($msg, $err);
                }
            } else {
                throw new Horde_Ldap_Exception("Unkown Option requested");
            }
        } else {
            throw new Horde_Ldap_Exception("Could not set LDAP option: No LDAP connection");
        }
    }

    /**
     * Get an LDAP option value
     *
     * @param string $option Option to get
     *
     * @access public
     * @return Horde_Ldap_Error|string Horde_Ldap_Error or option value
     */
    public function getOption($option)
    {
        if ($this->_link) {
            if (defined($option)) {
                if (@ldap_get_option($this->_link, constant($option), $value)) {
                    return $value;
                } else {
                    $err = @ldap_errno($this->_link);
                    if ($err) {
                        $msg = @ldap_err2str($err);
                    } else {
                        $err = Horde_Ldap::_ERROR;
                        $msg = $this->errorMessage($err);
                    }
                    throw new Horde_Ldap_Exception($msg, $err);
                }
            } else {
                throw new Horde_Ldap_Exception("Unkown Option requested");
            }
        } else {
            throw new Horde_Ldap_Exception("No LDAP connection");
        }
    }

    /**
     * Get the LDAP_PROTOCOL_VERSION that is used on the connection.
     *
     * A lot of ldap functionality is defined by what protocol version the ldap server speaks.
     * This might be 2 or 3.
     *
     * @return int
     */
    public function getLDAPVersion()
    {
        if ($this->_link) {
            $version = $this->getOption("LDAP_OPT_PROTOCOL_VERSION");
        } else {
            $version = $this->_config['version'];
        }
        return $version;
    }

    /**
     * Set the LDAP_PROTOCOL_VERSION that is used on the connection.
     *
     * @param int     $version LDAP-version that should be used
     * @param boolean $force   If set to true, the check against the rootDSE will be skipped
     *
     * @return true  true
     * @todo Checking via the rootDSE takes much time - why? fetching and instanciation is quick!
     */
    public function setLDAPVersion($version = 0, $force = false)
    {
        if (!$version) {
            $version = $this->_config['version'];
        }

        //
        // Check to see if the server supports this version first.
        //
        // Todo: Why is this so horribly slow?
        // $this->rootDse() is very fast, as well as Horde_Ldap_RootDSE::fetch()
        // seems like a problem at copiyng the object inside PHP??
        // Additionally, this is not always reproducable...
        //
        if (!$force) {
            $rootDSE = $this->rootDse();
            $supported_versions = $rootDSE->getValue('supportedLDAPVersion');
            if (is_string($supported_versions)) {
                $supported_versions = array($supported_versions);
            }
            $check_ok = in_array($version, $supported_versions);
        }

        if ($force || $check_ok) {
            return $this->setOption("LDAP_OPT_PROTOCOL_VERSION", $version);
        } else {
            throw new Horde_Ldap_Exception("LDAP Server does not support protocol version " . $version);
        }
    }


    /**
     * Tells if a DN does exist in the directory
     *
     * @param string|Horde_Ldap_Entry $dn The DN of the object to test
     *
     * @return boolean
     */
    public function dnExists($dn)
    {
        if ($dn instanceof Horde_Ldap_Entry) {
             $dn = $dn->dn();
        }
        if (false === is_string($dn)) {
            throw new Horde_Ldap_Exception('Parameter $dn is not a string nor an entry object!');
        }

        // make dn relative to parent
        $base = Horde_Ldap_Util::ldap_explode_dn($dn, array('casefold' => 'none', 'reverse' => false, 'onlyvalues' => false));

        $entry_rdn = array_shift($base);
        if (is_array($entry_rdn)) {
            // maybe the dn consist of a multivalued RDN, we must build the dn in this case
            // because the $entry_rdn is an array!
            $filter_dn = Horde_Ldap_Util::canonical_dn($entry_rdn);
        }
        $base = Horde_Ldap_Util::canonical_dn($base);

        $result = @ldap_list($this->_link, $base, $entry_rdn, array(), 1, 1);
        if (@ldap_count_entries($this->_link, $result)) {
            return true;
        }
        if (ldap_errno($this->_link) == 32) {
            return false;
        }
        if (ldap_errno($this->_link) != 0) {
            throw new Horde_Ldap_Exception(ldap_error($this->_link), ldap_errno($this->_link));
        }
        return false;
    }


    /**
     * Get a specific entry based on the DN
     *
     * @param string $dn   DN of the entry that should be fetched
     * @param array  $attr Array of Attributes to select. If ommitted, all attributes are fetched.
     *
     * @return Horde_Ldap_Entry  Reference to a Horde_Ldap_Entry object
     * @todo Maybe check against the shema should be done to be sure the attribute type exists
     */
    public function &getEntry($dn, $attr = array())
    {
        if (!is_array($attr)) {
            $attr = array($attr);
        }
        $result = $this->search($dn, '(objectClass=*)',
                                array('scope' => 'base', 'attributes' => $attr));
        if ($result->count() == 0) {
            throw new Horde_Ldap_Exception('Could not fetch entry '.$dn.': no entry found');
        }
        $entry = $result->shiftEntry();
        if (false == $entry) {
            throw new Horde_Ldap_Exception('Could not fetch entry (error retrieving entry from search result)');
        }
        return $entry;
    }

    /**
     * Rename or move an entry
     *
     * This method will instantly carry out an update() after the move,
     * so the entry is moved instantly.
     * You can pass an optional Horde_Ldap object. In this case, a cross directory
     * move will be performed which deletes the entry in the source (THIS) directory
     * and adds it in the directory $target_ldap.
     * A cross directory move will switch the Entrys internal LDAP reference so
     * updates to the entry will go to the new directory.
     *
     * Note that if you want to do a cross directory move, you need to
     * pass an Horde_Ldap_Entry object, otherwise the attributes will be empty.
     *
     * @param string|Horde_Ldap_Entry $entry       Entry DN or Entry object
     * @param string                 $newdn       New location
     * @param Horde_Ldap              $target_ldap (optional) Target directory for cross server move; should be passed via reference
     *
     * @return true
     */
    public function move($entry, $newdn, $target_ldap = null)
    {
        if (is_string($entry)) {
            $entry_o = $this->getEntry($entry);
        } else {
            $entry_o =& $entry;
        }
        if (!$entry_o instanceof Horde_Ldap_Entry) {
            throw new Horde_Ldap_Exception('Parameter $entry is expected to be a Horde_Ldap_Entry object! (If DN was passed, conversion failed)');
        }
        if (null !== $target_ldap && !$target_ldap instanceof Horde_Ldap) {
            throw new Horde_Ldap_Exception('Parameter $target_ldap is expected to be a Horde_Ldap object!');
        }

        if ($target_ldap && $target_ldap !== $this) {
            // cross directory move
            if (is_string($entry)) {
                throw new Horde_Ldap_Exception('Unable to perform cross directory move: operation requires a Horde_Ldap_Entry object');
            }
            if ($target_ldap->dnExists($newdn)) {
                throw new Horde_Ldap_Exception('Unable to perform cross directory move: entry does exist in target directory');
            }
            $entry_o->dn($newdn);
            try {
                $target_ldap->add($entry_o);
            } catch (Exception $e) {
                throw new Horde_Ldap_Exception('Unable to perform cross directory move: '.$e->getMessage().' in target directory');
            }

            try {
                $this->delete($entry_o->currentDN());
            } catch (Exception $e) {
                try {
                    $add_error_string = '';
                    $target_ldap->delete($entry_o); // undo add
                } catch (Exception $e) {
                    $add_error_string = 'Additionally, the deletion (undo add) of $entry in target directory failed.';
                }
                throw new Horde_Ldap_Exception('Unable to perform cross directory move: '.$e->getMessage().' in source directory. '.$add_error_string);
            }
            $entry_o->setLDAP($target_ldap);
            return true;
        } else {
            // local move
            $entry_o->dn($newdn);
            $entry_o->setLDAP($this);
            return $entry_o->update();
        }
    }

    /**
     * Copy an entry to a new location
     *
     * The entry will be immediately copied.
     * Please note that only attributes you have
     * selected will be copied.
     *
     * @param Horde_Ldap_Entry &$entry Entry object
     * @param string          $newdn  New FQF-DN of the entry
     *
     * @return Horde_Ldap_Entry  Error Message or reference to the copied entry
     */
    public function &copy(&$entry, $newdn)
    {
        if (!$entry instanceof Horde_Ldap_Entry) {
            throw new Horde_Ldap_Exception('Parameter $entry is expected to be a Horde_Ldap_Entry object');
        }

        $newentry = Horde_Ldap_Entry::createFresh($newdn, $entry->getValues());
        $result   = $this->add($newentry);

        return $newentry;
    }


    /**
     * Returns the string for an ldap errorcode.
     *
     * Made to be able to make better errorhandling
     * Function based on DB::errorMessage()
     * Tip: The best description of the errorcodes is found here:
     * http://www.directory-info.com/Ldap/LDAPErrorCodes.html
     *
     * @param int $errorcode Error code
     *
     * @return string The errorstring for the error.
     */
    public function errorMessage($errorcode)
    {
        $errorMessages = array(
                              0x00 => "LDAP_SUCCESS",
                              0x01 => "LDAP_OPERATIONS_ERROR",
                              0x02 => "LDAP_PROTOCOL_ERROR",
                              0x03 => "LDAP_TIMELIMIT_EXCEEDED",
                              0x04 => "LDAP_SIZELIMIT_EXCEEDED",
                              0x05 => "LDAP_COMPARE_FALSE",
                              0x06 => "LDAP_COMPARE_TRUE",
                              0x07 => "LDAP_AUTH_METHOD_NOT_SUPPORTED",
                              0x08 => "LDAP_STRONG_AUTH_REQUIRED",
                              0x09 => "LDAP_PARTIAL_RESULTS",
                              0x0a => "LDAP_REFERRAL",
                              0x0b => "LDAP_ADMINLIMIT_EXCEEDED",
                              0x0c => "LDAP_UNAVAILABLE_CRITICAL_EXTENSION",
                              0x0d => "LDAP_CONFIDENTIALITY_REQUIRED",
                              0x0e => "LDAP_SASL_BIND_INPROGRESS",
                              0x10 => "LDAP_NO_SUCH_ATTRIBUTE",
                              0x11 => "LDAP_UNDEFINED_TYPE",
                              0x12 => "LDAP_INAPPROPRIATE_MATCHING",
                              0x13 => "LDAP_CONSTRAINT_VIOLATION",
                              0x14 => "LDAP_TYPE_OR_VALUE_EXISTS",
                              0x15 => "LDAP_INVALID_SYNTAX",
                              0x20 => "LDAP_NO_SUCH_OBJECT",
                              0x21 => "LDAP_ALIAS_PROBLEM",
                              0x22 => "LDAP_INVALID_DN_SYNTAX",
                              0x23 => "LDAP_IS_LEAF",
                              0x24 => "LDAP_ALIAS_DEREF_PROBLEM",
                              0x30 => "LDAP_INAPPROPRIATE_AUTH",
                              0x31 => "LDAP_INVALID_CREDENTIALS",
                              0x32 => "LDAP_INSUFFICIENT_ACCESS",
                              0x33 => "LDAP_BUSY",
                              0x34 => "LDAP_UNAVAILABLE",
                              0x35 => "LDAP_UNWILLING_TO_PERFORM",
                              0x36 => "LDAP_LOOP_DETECT",
                              0x3C => "LDAP_SORT_CONTROL_MISSING",
                              0x3D => "LDAP_INDEX_RANGE_ERROR",
                              0x40 => "LDAP_NAMING_VIOLATION",
                              0x41 => "LDAP_OBJECT_CLASS_VIOLATION",
                              0x42 => "LDAP_NOT_ALLOWED_ON_NONLEAF",
                              0x43 => "LDAP_NOT_ALLOWED_ON_RDN",
                              0x44 => "LDAP_ALREADY_EXISTS",
                              0x45 => "LDAP_NO_OBJECT_CLASS_MODS",
                              0x46 => "LDAP_RESULTS_TOO_LARGE",
                              0x47 => "LDAP_AFFECTS_MULTIPLE_DSAS",
                              0x50 => "LDAP_OTHER",
                              0x51 => "LDAP_SERVER_DOWN",
                              0x52 => "LDAP_LOCAL_ERROR",
                              0x53 => "LDAP_ENCODING_ERROR",
                              0x54 => "LDAP_DECODING_ERROR",
                              0x55 => "LDAP_TIMEOUT",
                              0x56 => "LDAP_AUTH_UNKNOWN",
                              0x57 => "LDAP_FILTER_ERROR",
                              0x58 => "LDAP_USER_CANCELLED",
                              0x59 => "LDAP_PARAM_ERROR",
                              0x5a => "LDAP_NO_MEMORY",
                              0x5b => "LDAP_CONNECT_ERROR",
                              0x5c => "LDAP_NOT_SUPPORTED",
                              0x5d => "LDAP_CONTROL_NOT_FOUND",
                              0x5e => "LDAP_NO_RESULTS_RETURNED",
                              0x5f => "LDAP_MORE_RESULTS_TO_RETURN",
                              0x60 => "LDAP_CLIENT_LOOP",
                              0x61 => "LDAP_REFERRAL_LIMIT_EXCEEDED",
                              1000 => "Unknown Error"
                              );

         return isset($errorMessages[$errorcode]) ?
            $errorMessages[$errorcode] :
            $errorMessages[Horde_Ldap::_ERROR] . ' (' . $errorcode . ')';
    }

    /**
     * Gets a rootDSE object
     *
     * This either fetches a fresh rootDSE object or returns it from
     * the internal cache for performance reasons, if possible.
     *
     * @param array $attrs Array of attributes to search for
     *
     * @access public
     * @return Horde_Ldap_RootDSE Horde_Ldap_RootDSE object
     */
    public function &rootDse($attrs = null)
    {
        if ($attrs !== null && !is_array($attrs)) {
            throw new Horde_Ldap_Exception('Parameter $attr is expected to be an array');
        }

        $attrs_signature = serialize($attrs);

        // see if we need to fetch a fresh object, or if we already
        // requested this object with the same attributes
        if (true || !array_key_exists($attrs_signature, $this->_rootDSE_cache)) {
            $rootdse =& Horde_Ldap_RootDSE::fetch($this, $attrs);

            // search was ok, store rootDSE in cache
            $this->_rootDSE_cache[$attrs_signature] = $rootdse;
        }
        return $this->_rootDSE_cache[$attrs_signature];
    }

    /**
     * Alias function of rootDse() for perl-ldap interface
     *
     * @access public
     * @see rootDse()
     * @return Horde_Ldap_RootDSE
     */
    public function &root_dse()
    {
        $args = func_get_args();
        return call_user_func_array(array(&$this, 'rootDse'), $args);
    }

    /**
     * Get a schema object
     *
     * @param string $dn (optional) Subschema entry dn
     *
     * @access public
     * @return Horde_Ldap_Schema  Horde_Ldap_Schema object
     */
    public function &schema($dn = null)
    {
        // If a schema caching object is registered, we use that to fetch
        // a schema object.
        // See registerSchemaCache() for more info on this.
        // FIXME: Convert to Horde_Cache
        if ($this->_schema === null) {
            if ($this->_schema_cache) {
               $cached_schema = $this->_schema_cache->loadSchema();
               if ($cached_schema instanceof Horde_Ldap_Schema) {
                   $this->_schema = $cached_schema;
               }
            }
        }

        // Fetch schema, if not tried before and no cached version available.
        // If we are already fetching the schema, we will skip fetching.
        if ($this->_schema === null) {
            // store a temporary error message so subsequent calls to schema() can
            // detect, that we are fetching the schema already.
            // Otherwise we will get an infinite loop at Horde_Ldap_Schema::fetch()
            $this->_schema = new Horde_Ldap_Exception('Schema not initialized');
            $this->_schema = Horde_Ldap_Schema::fetch($this, $dn);

            // If schema caching is active, advise the cache to store the schema
            if ($this->_schema_cache) {
                $caching_result = $this->_schema_cache->storeSchema($this->_schema);
            }
        }
        return $this->_schema;
    }

    /**
     * Enable/disable persistent schema caching
     *
     * Sometimes it might be useful to allow your scripts to cache
     * the schema information on disk, so the schema is not fetched
     * every time the script runs which could make your scripts run
     * faster.
     *
     * This method allows you to register a custom object that
     * implements your schema cache. Please see the SchemaCache interface
     * (SchemaCache.interface.php) for informations on how to implement this.
     * To unregister the cache, pass null as $cache parameter.
     *
     * For ease of use, Horde_Ldap provides a simple file based cache
     * which is used in the example below. You may use this, for example,
     * to store the schema in a linux tmpfs which results in the schema
     * beeing cached inside the RAM which allows nearly instant access.
     * <code>
     *    // Create the simple file cache object that comes along with Horde_Ldap
     *    $mySchemaCache_cfg = array(
     *      'path'    =>  '/tmp/Horde_Ldap_Schema.cache',
     *      'max_age' =>  86400   // max age is 24 hours (in seconds)
     *    );
     *    $mySchemaCache = new Horde_Ldap_SimpleFileSchemaCache($mySchemaCache_cfg);
     *    $ldap = new Horde_Ldap::connect(...);
     *    $ldap->registerSchemaCache($mySchemaCache); // enable caching
     *    // now each call to $ldap->schema() will get the schema from disk!
     * </code>
     *
     * @param Horde_Ldap_SchemaCache|null $cache Object implementing the Horde_Ldap_SchemaCache interface
     *
     * @return true|Horde_Ldap_Error
     * FIXME: Convert to Horde_Cache
     */
    public function registerSchemaCache($cache) {
        if (is_null($cache)
        || (is_object($cache) && in_array('Horde_Ldap_SchemaCache', class_implements($cache))) ) {
            $this->_schema_cache = $cache;
            return true;
        } else {
            throw new Horde_Ldap_Exception('Custom schema caching object is either no '.
                'valid object or does not implement the Horde_Ldap_SchemaCache interface!');
        }
    }


    /**
     * Checks if PHP's LDAP extension is loaded
     *
     * If it is not loaded, it tries to load it manually using PHP's dl().
     * It knows both windows-dll and *nix-so.
     *
     * @static
     * @return true
     */
    public static function checkLDAPExtension()
    {
        if (!extension_loaded('ldap') && !@dl('ldap.' . PHP_SHLIB_SUFFIX)) {
            throw new Horde_Ldap_Exception("Unable to locate PHP LDAP extension. Please install it before using the Horde_Ldap package.");
        } else {
            return true;
        }
    }

    /**
     * Encodes given attributes to UTF8 if needed by schema
     *
     * This function takes attributes in an array and then checks against the schema if they need
     * UTF8 encoding. If that is so, they will be encoded. An encoded array will be returned and
     * can be used for adding or modifying.
     *
     * $attributes is expected to be an array with keys describing
     * the attribute names and the values as the value of this attribute:
     * <code>$attributes = array('cn' => 'foo', 'attr2' => array('mv1', 'mv2'));</code>
     *
     * @param array $attributes Array of attributes
     *
     * @access public
     * @return array|Horde_Ldap_Error Array of UTF8 encoded attributes or Error
     */
    public function utf8Encode($attributes)
    {
        return $this->utf8($attributes, 'utf8_encode');
    }

    /**
     * Decodes the given attribute values if needed by schema
     *
     * $attributes is expected to be an array with keys describing
     * the attribute names and the values as the value of this attribute:
     * <code>$attributes = array('cn' => 'foo', 'attr2' => array('mv1', 'mv2'));</code>
     *
     * @param array $attributes Array of attributes
     *
     * @access public
     * @see utf8Encode()
     * @return array|Horde_Ldap_Error Array with decoded attribute values or Error
     */
    public function utf8Decode($attributes)
    {
        return $this->utf8($attributes, 'utf8_decode');
    }

    /**
     * Encodes or decodes attribute values if needed
     *
     * @param array $attributes Array of attributes
     * @param array $function   Function to apply to attribute values
     *
     * @access protected
     * @return array|Horde_Ldap_Error Array of attributes with function applied to values or Error
     */
    protected function utf8($attributes, $function)
    {
        if (!is_array($attributes) || array_key_exists(0, $attributes)) {
            throw new Horde_Ldap_Exception('Parameter $attributes is expected to be an associative array');
        }

        if (!$this->_schema) {
            $this->_schema = $this->schema();
        }

        if (!$this->_link || !function_exists($function)) {
            return $attributes;
        }

        if (is_array($attributes) && count($attributes) > 0) {

            foreach ($attributes as $k => $v) {

                if (!isset($this->_schemaAttrs[$k])) {

                    try {
                        $attr = $this->_schema->get('attribute', $k);
                    } catch (Exception $e) {
                        continue;
                    }

                    if (false !== strpos($attr['syntax'], '1.3.6.1.4.1.1466.115.121.1.15')) {
                        $encode = true;
                    } else {
                        $encode = false;
                    }
                    $this->_schemaAttrs[$k] = $encode;

                } else {
                    $encode = $this->_schemaAttrs[$k];
                }

                if ($encode) {
                    if (is_array($v)) {
                        foreach ($v as $ak => $av) {
                            $v[$ak] = call_user_func($function, $av);
                        }
                    } else {
                        $v = call_user_func($function, $v);
                    }
                }
                $attributes[$k] = $v;
            }
        }
        return $attributes;
    }

    /**
     * Get the LDAP link resource.  It will loop attempting to
     * re-establish the connection if the connection attempt fails and
     * auto_reconnect has been turned on (see the _config array documentation).
     *
     * @access public
     * @return resource LDAP link
     */
    public function &getLink()
    {
        if ($this->_config['auto_reconnect']) {
            while (true) {
                //
                // Return the link handle if we are already connected.  Otherwise
                // try to reconnect.
                //
                if ($this->_link !== false) {
                    return $this->_link;
                } else {
                    $this->performReconnect();
                }
            }
        }
        return $this->_link;
    }

        /**
     * Return a boolean expression using the specified operator.
     *
     * @param string $lhs    The attribute to test.
     * @param string $op     The operator.
     * @param string $rhs    The comparison value.
     * @param array $params  Any additional parameters for the operator. @since
     *                       Horde 3.2
     *
     * @return string  The LDAP search fragment.
     */
    public static function buildClause($lhs, $op, $rhs, $params = array())
    {
        switch ($op) {
        case 'LIKE':
            if (empty($rhs)) {
                return '(' . $lhs . '=*)';
            } elseif (!empty($params['begin'])) {
                return sprintf('(|(%s=%s*)(%s=* %s*))', $lhs, Horde_LDAP::quote($rhs), $lhs, Horde_LDAP::quote($rhs));
            } elseif (!empty($params['approximate'])) {
                return sprintf('(%s=~%s)', $lhs, Horde_LDAP::quote($rhs));
            }
            return sprintf('(%s=*%s*)', $lhs, Horde_LDAP::quote($rhs));

        default:
            return sprintf('(%s%s%s)', $lhs, $op, Horde_LDAP::quote($rhs));
        }
    }


    /**
     * Escape characters with special meaning in LDAP searches.
     *
     * @param string $clause  The string to escape.
     *
     * @return string  The escaped string.
     */
    public static function quote($clause)
    {
        return str_replace(array('\\',   '(',  ')',  '*',  "\0"),
                           array('\\5c', '\(', '\)', '\*', "\\00"),
                           $clause);
    }

    /**
     * Take an array of DN elements and properly quote it according to RFC
     * 1485.
     *
     * @param array $parts  An array of tuples containing the attribute
     *                      name and that attribute's value which make
     *                      up the DN. Example:
     *
     *    $parts = array(0 => array('cn', 'John Smith'),
     *                   1 => array('dc', 'example'),
     *                   2 => array('dc', 'com'));
     *
     * @return string  The properly quoted string DN.
     */
    public static function quoteDN($parts)
    {
        $dn = '';
        $count = count($parts);
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                $dn .= ',';
            }
            $dn .= $parts[$i][0] . '=';

            // See if we need to quote the value.
            if (preg_match('/^\s|\s$|\s\s|[,+="\r\n<>#;]/', $parts[$i][1])) {
                $dn .= '"' . str_replace('"', '\\"', $parts[$i][1]) . '"';
            } else {
                $dn .= $parts[$i][1];
            }
        }

        return $dn;
    }
}
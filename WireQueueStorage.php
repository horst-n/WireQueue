<?php
/**
*  Module:  Wire Queue Storage Abstract
*  Author:  Horst Nogajski, http://nogajski.de/
*  Date:    07.02.2016
*  Version: 1.0.2
*
*  ProcessWire 2.5+
*  Copyright (C) 2010 by Ryan Cramer
*  Licensed under GNU/GPL v2, see LICENSE.TXT
*
*  http://www.processwire.com
*  http://www.ryancramer.com
*/

abstract class WireQueueStorage extends WireData implements Module {

    // See example 1) at the end of class
    // Check if storage is ready2use and define mandatory and optional information.
    // See commented example at the end of this class ->
    abstract public function hookAppendQueueStorageType(HookEvent $event);

    // See examples 5) at the end of class
    abstract protected function ready2use();

    // methods to handle data
    abstract public function createStorage();
    abstract public function addItem($arrayData);
    abstract public function getItem($worker = null);
    abstract public function itemCount();
    abstract public function getItems($count, $worker = null); // Returns an array of items - this can be empty
    abstract public function isEmpty();
    abstract public function purgeItems();

    // additionally copy / embedd following methods and properties into your storage module:
    #  public static function getModuleInfo()  -> see example 6) below
    #  public function __construct()           -> see example 2) below
    #  public function ___install()            -> see example 3) below
    #  public function ___uninstall()          -> see example 4) below




    public static function getModuleInfo() {
        self::$moduleInfo['title'] = ucwords(trim(str_replace(array('wire', 'queue'), '', strtolower(self::$moduleInfo['title']))));
        $requirements = version_compare(self::$pwv, self::$pwvRequires, '<') ? 'WireQueue' : 'ProcessWire>=2.5.0, PHP>=5.3.8, WireQueue';
        if(isset(self::$moduleInfo['requires'])) {
            $requirements .= ', ' . self::$moduleInfo['requires'];
        }
        $info = array(
            'title'    => 'Wire Queue ' . self::$moduleInfo['title'],
            'version'  => self::$moduleInfo['version'],
            'author'   => self::$moduleInfo['author'],
            'summary'  => self::$moduleInfo['summary'],
            'singular' => false,
            'autoload' => true,
            'requires' => $requirements,
            'icon'     => 'exchange'
            );
        return $info;
    }

    private static $pwv = '';
    private static $pwvRequires = '2.5.0';
    protected static $moduleInfo = array(
        'title'   => 'Skeleton title',
        'version' => '0.0.1',
        'author'  => 'Skeleton author',
        'summary' => 'Skeleton summary',
        );
    protected static $fileExtension = 'txt';
    protected static $states = array();

    protected $filebased = false;
    protected $assetsPath = null;

    public function __construct() {
        self::$pwv = wire('config')->version;
        self::$states = array(
            1 => $this->_('New empty Queue'),
            2 => $this->_('Queue is enabled'),
            3 => $this->_('Queue is paused'),
            4 => $this->_('Queue is closed / archived')
            );
    }

    public function init() {
        $this->addHookBefore('Modules::uninstall', $this, 'hookBeforeModulesUninstall');
        $this->addHookAfter('WireQueue::loadQueueStorageModules', $this, 'hookAppendQueueStorageType');
    }

    // Mandatory Setting! When creating queue pages in the PW Admin, this is done automatically by the hook ->WireQueue() !
    public function setPageId($id) {
        $this->pageID = $id;
    }

    // Permanent store (not bound to sessions!) of the Assetspath. Mandatory for filebased Queue Storages!
    // When creating queue pages in the PW Admin, this is done automatically by the hook ->WireQueue() !
    public function setAssetspath($path) {
        $path = DIRECTORY_SEPARATOR == "\\" && substr($path, 1, 1) == ':' ? substr($path, 0, 2) . $this->sanitizer->pagePathName(substr($path, 2) . '/') : $this->sanitizer->pagePathName($path);
        if(!is_dir($path)) return false;
        // we need to save it permanent:
        $allData = $this->modules->getModuleConfigData(self::className());
        $data = isset($allData[$this->pageID]) ? $allData[$this->pageID] : array();
        $data = array_merge($data, array('assetsPath' => $path));
        $allData[$this->pageID] = $data;
        WireQueue::writeModuleConfigData(self::className(), $allData);
        $this->assetsPath = null; // reset
        $res = ($path == $this->getAssetspath()); // validate
        if($res) $this->assetsPath = $path; // renew on success
        return $res;
    }


    public function getPage() {
        // returns the PageObject of the WireQueue Page
        return $this->pages->get('id=' . $this->pageID);
    }

    public function getPageId() {
        return $this->pageID;
    }

    public function getState() {
        return (int)$this->getPage()->get(WireQueue::WIRE_QUEUE_FIELD2);
    }

    public function getStateStr() {
        return self::$states[$this->getState()];
    }


    public function archiveStorage() {
        if(!$this->ready2use()) return false;
        if(!$this->filebased) return true;
        $path = $this->getAssetspath();
        $src = $path . self::className() . '.' . self::$fileExtension;
        $dst = $path . self::className() . '.archived.' . self::$fileExtension;
        return @rename($src, $dst);
    }


    protected function getAssetspath() {
        if($this->assetsPath) return $this->assetsPath;
        $allData = $this->modules->getModuleConfigData(self::className());
        $data = isset($allData[$this->pageID]) ? $allData[$this->pageID] : array();
        if(!isset($data['assetsPath']) || !is_dir($data['assetsPath'])) return false;
        $this->assetsPath = $data['assetsPath'];
        return $this->assetsPath;
    }

    protected function getFilename() {
        $archived = 4 == $this->getState() ? '.archived' : '';
        $basename = self::className() . $archived . '.' . self::$fileExtension;
        $path = $this->getAssetspath();
        return false !== $path ? $path . strtolower($this->sanitizer->filename($basename)) : false;
    }

    protected function _addItem() {
        if(!$this->ready2use()) return false;
        if(!$this->_checkFileAccess()) return false;
        return true;
    }

    protected function _getItem() {
        if(!$this->ready2use()) return false;
        if(!$this->_checkFileAccess()) return false;
        return true;
    }

    protected function _itemCount() {
        if(!$this->ready2use()) return false;
        if(!$this->_checkFileAccess(false)) return false;
        return true;
    }

    private function _checkFileAccess($writeAccess = true) {
        if(!$this->filebased) return true;
        if(false === ($file = $this->getFilename())) return false;
        if($writeAccess && !is_writable($file)) return false;
        return is_readable($file);
    }


    public function ___install() {
        try {
            if(!$this->ready2use()) {
                $this->warning(sprintf($this->_("This module (%s) is installed but not functional! The System seems not to fullfill the requirements."), self::className()));
                return false;
            }
            $p = $this->getSelfPage(true);
            if(0 == $p->id) {
                $name = $this->sanitizer->pageName(WireQueue::WIRE_QUEUE_STORAGES);
                $toolsContainer = $this->pages->get("parent.id=2, name={$name}, include=hidden, template=" . self::WIRE_QUEUE_TEMPLATE_TOOLS);
                $this->error(sprintf($this->_("Unable to install this module: %s ! <br> Could not create a Page under: %s"), self::className(), $toolsContainer->path));
                return false;
            }
            return true;
        } catch(Exception $e) {
            $this->error($e->getMessage());
            return false;
        }
    }

    public function ___uninstall() {
        if(!$this->canBeFreed(true)) return false;
        try {
            $p = $this->getSelfPage(false);
            if(0 < $p->id) {
                // lets check if there exists any Wire Queue Pages of this StorageType
                $selector = array(
                    'include=all',
                    'template=' . WireQueue::WIRE_QUEUE_TEMPLATE_CHILDREN,
                    WireQueue::WIRE_QUEUE_FIELD . '.name=' . $p->name
                );
                $selector = implode(',', $selector);
                $this->emptyTrash($selector);
                $pa = $this->pages->find($selector);
                if(0 == $pa->count()) {
                    // Ready! There are no References to our StorageTypeModule, we can securely uninstall it.
                    $p->delete();
                    return true;
                }
                // set the page to unpublished
                $p->status(page::statusUnpublished);
                $p->save();
                return false;
            }
        } catch(Exception $e) {
            $this->error($e->getMessage());
            return false;
        }
    }

    public function hookBeforeModulesUninstall(HookEvent $event) {
        $class = $event->arguments[0];
        $class = $event->object->getModuleClass($class);
        if(self::className() != $class) return;
        $num = 0;
        if($this->canBeFreed(true, $num)) return;
        $msg = $this->_("Oups! There are %d References from Wire Queue Pages to this StorageTypeModule (%s). We cannot uninstall it, the functionality of those Pages would break then!");
        $this->warning(sprintf($msg, $num, $class));
        $event->return = false;
        $event->replace = true;
    }

    private function canBeFreed($emptyTrash = false, &$num) {
        $p = $this->getSelfPage(false);
        if(0 == $p->id) return true;
        // lets check if there exist any Wire Queue Pages of this StorageType
        $selector = array(
            'include=all',
            'template=' . WireQueue::WIRE_QUEUE_TEMPLATE_CHILDREN,
            WireQueue::WIRE_QUEUE_FIELD . '.name=' . $p->name
        );
        $selector = implode(',', $selector);
        if($emptyTrash) $this->emptyTrash($selector);
        $pa = $this->pages->find($selector);
        $num = $pa->count();
        return (0 == $num);
    }

    private function emptyTrash($selector = '') {
        // remove WireQueuePages from Trash, so that those cannot block deletion of templates and fields
        $selector = trim($selector);
        if($selector) $selector = "parent=/trash/, $selector";
        if('' == $selector) $selector = 'parent=/trash/, include=all, template=' . WireQueue::WIRE_QUEUE_TEMPLATE_CHILDREN . '|' . WireQueue::WIRE_QUEUE_TEMPLATE_PARENT . '|' . WireQueue::WIRE_QUEUE_TEMPLATE_TOOLS;
        $trashPages = $this->pages->find($selector);
        foreach($trashPages as $child) $child->delete();
    }

    private function getSelfPage($create = false) {
        $myClass = self::className();
        $myName = $this->sanitizer->pageName($myClass);
        $parentName = $this->sanitizer->pageName(WireQueue::WIRE_QUEUE_STORAGES);
        $t = $this->templates->get('name=' . WireQueue::WIRE_QUEUE_TEMPLATE_TOOLS);
        $toolsContainer = $this->pages->get("parent.id=2, name={$parentName}, include=hidden, template=" . WireQueue::WIRE_QUEUE_TEMPLATE_TOOLS);
        // get / create the storagetype page
        $p = $this->pages->get("parent.name={$parentName}, template=" . WireQueue::WIRE_QUEUE_TEMPLATE_TOOLS . ", name={$myName}, include=hidden");
        if(0 == $p->id && $create) {
            $p = new Page($t);
            $p->parent = $toolsContainer;
            $p->title = $myClass;
            $p->status(Page::statusHidden);
            $p->save();
        }
        return $p;
    }

}


/****************************************************************
1) -> Example of method hookAppendQueueStorageType()

    public function hookAppendQueueStorageType(HookEvent $event) {
        // first check if this storage module is ready to use
        if(!$this->ready2use()) return;
        // fetch current result array from hookEvent
        $queueTypes = $event->return;
        // populate $moduleInfo data
        self::getModuleInfo();
        // add info of this storage module to the events result set
        $queueTypes[__CLASS__] = array_merge(self::$moduleInfo, array(
            // mandatory data: type, description
            'type'        => 'textfile',
            'description' => $this->_('This module uses plain Textfiles as Storage. One entry per line. Entries will be serialized before storing them.'),
            // optionally add more data:
            'infodummy1'  => 'infodummy1',
            'infodummy2'  => 'infodummy2'
        ));
        // write back the merged info array to the HookEvent
        $event->return = $queueTypes;
        $event->replace = true;
    }

   *********************************************************

2) -> Example Constructor

    public function __construct() {
        // set the flag to true if this module uses files
        #$this->filebased = true;
        // optionally change the default file extension "txt"
        #self::$fileExtension = 'txt';
        // now call the parent constructor and after that, do other stuff if you need to
        parent::__construct();
    }


   *********************************************************

3) -> install

    public function ___install() {
        if(!parent::___install()) {
            return false;
        }
        // optionally run your own uninstallation routines here, ...
        // ...
        return true;
    }


   *********************************************************

4) -> uninstall

    public function ___uninstall() {
        if(!parent::___uninstall()) {
            return false;
        }
        // optionally run your own uninstallation routines here, ...
        // ...
        return true;
    }


   *********************************************************

5) -> Examples ready2use:

    protected function ready2use() {
        // do all checks to determine if this storage module is ready for use in this system,
        // return boolean true | false
        return class_exists('SQlite3');
    }

    public function ready2use() {
        // nothing to check here for this handler :)
        return true;
    }


   *********************************************************

6) -> Example getModuleInfo:

    public static function getModuleInfo() {
        self::$moduleInfo = array(
            'title'   => 'Wire Queue OverwrittenSkeleton',
            'version' => '1.0.0',
            'author'  => 'author OverwrittenSkeleton',
            'summary' => 'summary OverwrittenSkeleton'
            );
        return parent::getModuleInfo();
    }

*****************************************************************/

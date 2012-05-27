<?php

/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2012 
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 * ************************************************************* */

/**
 *
 *
 * @package t3_less
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 *
 */
require_once (t3lib_extMgm::extPath('t3_less') . 'Resources/Private/Lib/lessc.inc.php');

class Tx_T3Less_Controller_LessController extends Tx_Extbase_MVC_Controller_ActionController {

    /**
     * configuration array from constants
     * @var array $configuration
     */
    protected $configuration;

    /**
     * folder for lessfiles
     * @var string $lessfolder 
     */
    protected $lessfolder;

    /**
     * folder for compiled files 
     * @var string $outputfolder
     */
    protected $outputfolder;
    
    
    
        
    public function __construct() {
        $objectManager = t3lib_div::makeInstance('Tx_Extbase_Object_ObjectManager');
        $configurationManager = $objectManager->get('Tx_Extbase_Configuration_ConfigurationManagerInterface');
       
        $configuration = $configurationManager->getConfiguration(
            Tx_Extbase_Configuration_ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
                'T3Less',
                ''
                
        );
        $this->configuration = $configuration;
        parent::__construct();
    }

    /**
     * action base
     * 
     */
    public function baseAction() {
        if (TYPO3_MODE != 'FE') {
            return;
        }
        $this->lessfolder = $this->configuration['files']['pathToLessFiles'];
        $this->outputfolder = $this->configuration['files']['outputFolder'];
        $this->getLessFiles();
    }
    
    
    /*
     * getLessFiles
     * generates file array from defined lessfolder and hooks
     * 
     */
    public function getLessFiles() {
        // files array holds less files
        $files = array();
        // compiler activated?
        if ($this->configuration['other']['activateCompiler']) {
            // folders defined?
            if ($this->lessfolder && $this->outputfolder) {
                // are there files in the defined less folder?
                if (t3lib_div::getFilesInDir($this->lessfolder, "less", TRUE)) {
                    $files = t3lib_div::getFilesInDir($this->lessfolder, "less", TRUE);
                } else {
                    echo $this->wrapErrorMessage(Tx_Extbase_Utility_Localization::translate('noLessFilesInFolder', $this->extensionName, $arguments = array('s' => $this->lessfolder)));
                }
            } else {
                echo $this->wrapErrorMessage(Tx_Extbase_Utility_Localization::translate('emptyPathes', $this->extensionName));
            }
        }


        /* Hook to pass less-files from other extension, see manual */
        if ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3less']['addForeignLessFiles']) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3less']['addForeignLessFiles'] as $a) {
                $files[] = t3lib_div::getFilesInDir($a, "less", TRUE);
            }
            $files = $this->flatArray(null, $files);
        }

        switch ($this->configuration['enable']['mode']) {
            case 'PHP-Compiler':
                $this->lessPhp($files);
                break;

            case 'JS-Compiler':
                $this->lessJs($files);
                break;
        }
    }
    

    /**
     * lessPhp
     * 
     * @return void 
     */
    public function lessPhp($files) {

        // create outputfolder if it does not exist
        if (!is_dir($this->outputfolder)) t3lib_div::mkdir_deep ($this->outputfolder);
        
        // compile each less-file
        foreach ($files as $file) {
            //get only the name of less file
            $filename = array_pop(explode('/', $file));
            $outputfile = $this->outputfolder . substr($filename, 0, -5) . '_' . md5_file(($file)) . '.css';
            if (!file_exists($outputfile)) {
                lessc::ccompile($file, $this->outputfolder . substr($filename, 0, -5) . '_' . md5_file(($file)) . '.css');
                t3lib_div::fixPermissions($outputfile, FALSE);
            }
            
        }
        // unlink compiled files which have no equal source less-file
        if ($this->configuration['other']['unlinkCssFilesWithNoSourceFile'] == 1) {
            $this->unlinkGeneratedFilesWithNoSourceFile($files);
        }

        foreach (t3lib_div::getFilesInDir($this->outputfolder, "css") as $cssFile) {
                $excludeFromPageRender = $this->configuration['phpcompiler']['filesettings'][substr($cssFile, 0, -37)]['excludeFromPageRenderer'];
                if(!$excludeFromPageRender || $excludeFromPageRender == 0) {
                    // array with filesettings from TS
                    $tsOptions = $this->configuration['phpcompiler']['filesettings'][substr($cssFile, 0, -37)];

                    $GLOBALS['TSFE']->getPageRenderer()->addCssFile(
                            $this->outputfolder . $cssFile, 
                            $rel = 'stylesheet', 
                            $media = $tsOptions['media'] ? $tsOptions['media'] : 'all',
                            $title = $tsOptions['title'] ? $tsOptions['title'] : '',
                            $compress = $tsOptions['compress'] >= '0' ? (boolean)$tsOptions['compress'] : TRUE, 
                            $forceOnTop = $tsOptions['forceOnTop'] >= '0' ? (boolean)$tsOptions['forceOnTop'] : FALSE, 
                            $allWrap = $tsOptions['allWrap'] ? $tsOptions['allWrap'] : '',
                            $excludeFromConcatenation = $tsOptions['excludeFromConcatenation'] >= '0' ? (boolean)$tsOptions['excludeFromConcatenation'] : FALSE
                    );
                }
            
        }
    }
    
     /**
     * lessJs
     * includes all less-files from defined lessfolder to head and less.js to the footer
     */
    public function lessJs($files) {
        // lessfolder defined
        
            // files in defined lessfolder?
            if ($files) {
                foreach ($files as $lessFile) {
                    $filename = array_pop(explode('/', $lessFile));
                    
                    $excludeFromPageRender = $this->configuration['jscompiler']['filesettings'][substr($filename, 0, -5)]['excludeFromPageRenderer'];
                    
                    if(!$excludeFromPageRender || $excludeFromPageRender == 0) {
                    // array with filesettings from TS
                    $tsOptions = $this->configuration['jscompiler']['filesettings'][substr($filename, 0, -5)];
                    
                    $GLOBALS['TSFE']->getPageRenderer()->addCssFile(
                            $lessFile, 
                            $rel = 'stylesheet/less', 
                            $media = $tsOptions['media'] ? $tsOptions['media'] : 'all',
                            $title = $tsOptions['title'] ? $tsOptions['title'] : '',
                            $compress = FALSE,
                            $forceOnTop = TRUE,
                            $allWrap = $tsOptions['allWrap'] ? $tsOptions['allWrap'] : '',
                            $excludeFromConcatenation = TRUE
                        );
                    }
                    
                }
                //include less.js to footer            
                $GLOBALS['TSFE']->getPageRenderer()->addJsFooterFile($file = t3lib_extMgm::siteRelPath('t3_less') . 'Resources/Public/Js/less-1.3.0.min.js', $type = 'text/javascript', $compress = TRUE, $forceOnTop = FALSE);
            } else {
                echo $this->wrapErrorMessage(Tx_Extbase_Utility_Localization::translate('noLessFilesInFolder', $this->extensionName, $arguments = array('s' => $this->lessfolder)));
            }
        
    }
   
    
    /**
     * unlink compiled files which have no equal source less-file
     * Only for mode "PHP-Compiler"
     */
    public function unlinkGeneratedFilesWithNoSourceFile($sourceFiles) {
        // all available sourcefiles 
        //$sourceFiles = t3lib_div::getFilesInDir($this->lessfolder, "less");

        // build array with md5 values from sourcefiles
        foreach ($sourceFiles as $file) {
            $srcArr[] .= md5_file($file);
        }

        // unlink every css file, which have no equal less-file
        // checked by comparing md5-string from filename with md5_file(sourcefile)
        foreach (t3lib_div::getFilesInDir($this->outputfolder, "css") as $cssFile) {
            $md5 = substr(substr($cssFile, 0, -4), -32);
            if (!in_array($md5, $srcArr)) {
                unlink($this->outputfolder . $cssFile);
            }
        }
    }

   

    /**
     * wraps error messages in a div
     * @param string $message
     * @return string $errMsg 
     */
    public function wrapErrorMessage($message) {
        $errMsg = ' <div class="t3-less-error-message" 
                    style="background:red;color:white;width:100%;font-size:14px;padding:5px;">
                    <b>T3_LESS error message: </b>'
                . $message .
                '</div> ';
        return $errMsg;
    }


    /* 
     * flatArray
     * little helper function to flatten multi arrays to flat arrays
     * @return array $elements
     */
    protected function flatArray($needle = null, $haystack = array()) {

        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($haystack));
        $elements = array();

        foreach ($iterator as $element) {

            if (is_null($needle) || $iterator->key() == $needle) {
                $elements[] = $element;
            }
        }

        return $elements;
    }
}

?>
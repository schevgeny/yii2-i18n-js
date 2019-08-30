<?php

namespace w3lifer\yii2;

use w3lifer\phpHelper\PhpHelper;
use Yii;
use yii\base\BaseObject;
use yii\helpers\Json;
use yii\web\View;

/**
 * @see https://github.com/yiisoft/yii2/issues/274
 */
class I18nJs extends BaseObject
{
    /**
     * @var string The path to the JS file relative to the `@webroot` directory.
     */
    public $jsFilename = 'js/i18n.js';

    public $useOneLanguage = true;

    /**
     * @var string
     */
    private $jsFilenameOnServer;

    /**
     * @var string
     */
    private $filenameForSavingModificationTime;

    /**
     * @var array
     */
    public $basePaths = [];

    /**
     * @var array
     */
    private $filenames = [];

    /**
     * @var integer
     */
    private $savedModificationTime;

    /**
     * @var integer
     */
    private $currentModificationTime;
    

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->jsFilenameOnServer =
            Yii::getAlias('@webroot') . '/' . $this->jsFilename;
        $dirname = dirname($this->jsFilenameOnServer);
        if (!file_exists($dirname)) {
            mkdir($dirname, 0777, true);
        }
        $this->filenameForSavingModificationTime =
            Yii::getAlias('@runtime') . '/i18n-js-modification-time';
        $this->basePaths = $this->getBasePaths();
        
        $this->filenames = $this->getFilenames();
      
        $this->savedModificationTime = $this->getSavedModificationTime();
        $this->currentModificationTime = $this->getCurrentModificationTime();
        if (
            !file_exists($this->jsFilenameOnServer) ||
            !$this->savedModificationTime ||
            $this->savedModificationTime !== $this->currentModificationTime
        ) {
            $this->saveJsFile();
            $this->saveModificationTime();
        }
        Yii::$app->view->registerJsFile(
            '@web/' . $this->jsFilename . '?v=' . $this->currentModificationTime
        );
        $this->registerJsScript();
    }

   

    private function getBasePaths()
    {
        if($this->basePaths){
            return  array_map(function($path){
                if($this->useOneLanguage){
                    $path .= '/' . Yii::$app->language;
                }
                return realpath(Yii::getAlias($path));
            }, $this->basePaths);
        }
        $basePaths = [];
        foreach (Yii::$app->i18n->translations as $category => $translation) {
            if ($category !== 'yii') {
                if (is_array($translation)) {
                    $path = 
                        isset($translation['basePath'])
                            ? realpath(Yii::getAlias($translation['basePath']))
                            : realpath(Yii::getAlias('@app/messages'));
                } elseif(isset($translation->basePath)) {
                    
                    $path = realpath(Yii::getAlias($translation->basePath));
                }
                if($this->useOneLanguage){
                   // $path .= '/' . Yii::$app->language;
                }
                $basePaths[] = $path; 
            }
        }
        return array_unique($basePaths);
    }

    private function getFilenames()
    {
        $filenames = [];
        foreach ($this->basePaths as $basePath) {
            foreach (
                PhpHelper::get_files_in_directory(
                    $basePath,
                    true,
                    ['php']
                ) as $filename
            ) {
                // https://github.com/w3lifer/yii2-i18n-js/issues/1
                if (preg_match(
                    '=^' .
                        preg_quote($basePath . DIRECTORY_SEPARATOR) .
                        '.+?' .
                   
                    '=',
                    $filename
                )) {
                    $filenames[] = $filename;
                }
            }
        }
        return $filenames;
    }

    private function getSavedModificationTime()
    {
        $modificationTime = 0;
        if (file_exists($this->filenameForSavingModificationTime)) {
            $modificationTime =
                (int)
                    file_get_contents($this->filenameForSavingModificationTime);
        }
        return $modificationTime;
    }

    private function getCurrentModificationTime()
    {
        $commonModificationTime = 0;
        foreach ($this->filenames as $filename) {
            $commonModificationTime += filemtime($filename);
        }
        return $commonModificationTime;
    }

    private function saveJsFile()
    {
        $result = [];
        foreach ($this->basePaths as $basePath) {
            foreach ($this->filenames as $filename) {
                $languageAndCategoryAsString =
                    str_replace($basePath . DIRECTORY_SEPARATOR, '', $filename);
                // Delete file extension (.php)
                $languageAndCategoryAsString =
                    substr($languageAndCategoryAsString, 0, -4);

                $languageAndCategoryAsArray =
                    explode(
                        DIRECTORY_SEPARATOR,
                        $languageAndCategoryAsString,
                        2
                    );
                /** @noinspection PhpIncludeInspection */
                if($this->useOneLanguage){
                    $result[Yii::$app->language] // Language
                        [$languageAndCategoryAsArray[0]] = // Category
                            include $filename;
                }else{
                    $result[$languageAndCategoryAsArray[0]] // Language
                        [$languageAndCategoryAsArray[1]] = // Category
                            include $filename;
                }
                
            }
        }
         return
            file_put_contents(
                $this->jsFilenameOnServer,
                'var YII_I18N_JS = ' . Json::encode($result) . ';' . "\n"
            );
    }

    private function saveModificationTime()
    {
        file_put_contents(
            $this->filenameForSavingModificationTime,
            $this->currentModificationTime . "\n"
        );
    }

    private function registerJsScript()
    {
        $sourceLanguage = strtolower(Yii::$app->sourceLanguage);
        $language = Yii::$app->language;
        $useOneLanguage = (int)$this->useOneLanguage;
        $js = <<<JS
;(function () {
  if (!('yii' in window)) {
    window.yii = {};
  }
  if (!('t' in window.yii)) {
    if (!document.documentElement.lang) {
      throw new Error(
        'You must specify the "lang" attribute for the <html> element'
      );
    }
    yii.t = function (category, message, params, language) {
        var localLang = document.documentElement.lang;
        if("{$useOneLanguage}"){
            localLang = "{$language}";
        }
      language = language || localLang;
      
      var translatedMessage;
      if (
        language === "{$sourceLanguage}" ||
        !YII_I18N_JS ||
        !YII_I18N_JS[language] ||
        !YII_I18N_JS[language][category] ||
        !YII_I18N_JS[language][category][message]
      ) {
        translatedMessage = message;
      } else {
        translatedMessage = YII_I18N_JS[language][category][message];
      }
     
      if (params) {
        Object.keys(params).map(function (key) {
          var escapedParam =
            // https://stackoverflow.com/a/6969486/4223982
            key.replace(/[\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, '\\$&');
          var regExp = new RegExp('\\\{' + escapedParam + '\\\}', 'g');
          translatedMessage = translatedMessage.replace(regExp, params[key]);
        });
      }
      return translatedMessage;
    };
  }
})();
JS;
        Yii::$app->view->registerJs($js, View::POS_END);
    }
}

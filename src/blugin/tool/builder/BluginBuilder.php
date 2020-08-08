<?php

/*
 *
 *  ____  _             _         _____
 * | __ )| |_   _  __ _(_)_ __   |_   _|__  __ _ _ __ ___
 * |  _ \| | | | |/ _` | | '_ \    | |/ _ \/ _` | '_ ` _ \
 * | |_) | | |_| | (_| | | | | |   | |  __/ (_| | | | | | |
 * |____/|_|\__,_|\__, |_|_| |_|   |_|\___|\__,_|_| |_| |_|
 *                |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author  Blugin team
 * @link    https://github.com/Blugin
 * @license https://www.gnu.org/licenses/lgpl-3.0 LGPL-3.0 License
 *
 *   (\ /)
 *  ( . .) ♥
 *  c(")(")
 */

declare(strict_types=1);

namespace blugin\tool\builder;

use blugin\tool\builder\printer\IPrinter;
use blugin\tool\builder\printer\PrettyPrinter;
use blugin\tool\builder\printer\ShortenPrinter;
use blugin\tool\builder\util\Utils;
use blugin\tool\builder\visitor\CommentOptimizingVisitor;
use blugin\tool\builder\visitor\ImportRemovingVisitor;
use blugin\tool\builder\visitor\LocalVariableRenamingVisitor;
use blugin\tool\builder\visitor\PrivateMethodRenamingVisitor;
use blugin\tool\builder\visitor\PrivatePropertyRenamingVisitor;
use blugin\tool\builder\visitor\renamer\MD5Renamer;
use blugin\tool\builder\visitor\renamer\ProtectRenamer;
use blugin\tool\builder\visitor\renamer\Renamer;
use blugin\tool\builder\visitor\renamer\SerialRenamer;
use blugin\tool\builder\visitor\renamer\ShortenRenamer;
use blugin\tool\builder\visitor\renamer\SpaceRenamer;
use FolderPluginLoader\FolderPluginLoader;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

class BluginBuilder extends PluginBase{
    public const RENAMER_PROECT = "protect";
    public const RENAMER_SHORTEN = "shorten";
    public const RENAMER_SERIAL = "serial";
    public const RENAMER_SPACE = "space";
    public const RENAMER_MD5 = "md5";
    /** @var Renamer[] renamer tag -> renamer instance */
    private $renamers = [];

    public const PRINTER_PRETTY = "pretty";
    public const PRINTER_SHORTEN = "shorten";
    /** @var IPrinter[] printer tag -> printer instance */
    private $printers = [];

    public function onLoad(){
        $this->renamers[self::RENAMER_PROECT] = new ProtectRenamer();
        $this->renamers[self::RENAMER_SHORTEN] = new ShortenRenamer();
        $this->renamers[self::RENAMER_SERIAL] = new SerialRenamer();
        $this->renamers[self::RENAMER_SPACE] = new SpaceRenamer();
        $this->renamers[self::RENAMER_MD5] = new MD5Renamer();

        $this->printers[self::PRINTER_PRETTY] = new PrettyPrinter();
        $this->printers[self::PRINTER_SHORTEN] = new ShortenPrinter();
    }

    /**
     * @param CommandSender $sender
     * @param Command       $command
     * @param string        $label
     * @param string[]      $args
     *
     * @return bool
     * @throws \ReflectionException
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(empty($args))
            return false;

        /** @var PluginBase[] $plugins */
        $plugins = [];
        $pluginManager = Server::getInstance()->getPluginManager();
        if($args[0] === "*"){
            foreach($pluginManager->getPlugins() as $pluginName => $plugin){
                if($plugin->getPluginLoader() instanceof FolderPluginLoader){
                    $plugins[$plugin->getName()] = $plugin;
                }
            }
        }else{
            foreach($args as $key => $pluginName){
                $plugin = Utils::getPlugin($pluginName);
                if($plugin === null){
                    $sender->sendMessage("{$pluginName} is invalid plugin name");
                }elseif(!($plugin->getPluginLoader() instanceof FolderPluginLoader)){
                    $sender->sendMessage("{$plugin->getName()} is not in folder plugin");
                }else{
                    $plugins[$plugin->getName()] = $plugin;
                }
            }
        }
        $pluginCount = count($plugins);
        $sender->sendMessage("Start build the {$pluginCount} plugins");

        if(!file_exists($dataFolder = $this->getDataFolder())){
            mkdir($dataFolder, 0777, true);
        }
        foreach($plugins as $pluginName => $plugin){
            $pharName = "{$pluginName}_v{$plugin->getDescription()->getVersion()}.phar";
            $this->buildPlugin($plugin);
            $sender->sendMessage("$pharName has been created on $dataFolder");
        }
        $sender->sendMessage("Complete built the {$pluginCount} plugins");
        return true;
    }

    /**
     * @param PluginBase $plugin
     *
     * @throws \ReflectionException
     */
    public function buildPlugin(PluginBase $plugin) : void{
        $reflection = new \ReflectionClass(PluginBase::class);
        $fileProperty = $reflection->getProperty("file");
        $fileProperty->setAccessible(true);
        $sourcePath = rtrim(realpath($fileProperty->getValue($plugin)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $pharPath = "{$this->getDataFolder()}{$plugin->getName()}_v{$plugin->getDescription()->getVersion()}.phar";

        $description = $plugin->getDescription();
        $metadata = [
            "name" => $description->getName(),
            "version" => $description->getVersion(),
            "main" => $description->getMain(),
            "api" => $description->getCompatibleApis(),
            "depend" => $description->getDepend(),
            "description" => $description->getDescription(),
            "authors" => $description->getAuthors(),
            "website" => $description->getWebsite(),
            "creationDate" => time()
        ];
        $this->buildPhar($sourcePath, $pharPath, $metadata);
    }

    /**
     * @param string $pharPath
     * @param string $filePath
     * @param array  $metadata
     */
    public function buildPhar(string $filePath, string $pharPath, array $metadata) : void{
        //Remove the existing PHAR file
        if(file_exists($pharPath)){
            try{
                \Phar::unlinkArchive($pharPath);
            }catch(\Exception $e){
                unlink($pharPath);
            }
        }

        //Remove the existing build folder and remake
        $buildPath = "{$this->getDataFolder()}build/";
        if(file_exists($buildPath)){
            Utils::removeDirectory($buildPath);
        }
        mkdir($buildPath, 0777, true);

        //Pre-build processing execution
        $config = $this->getConfig();
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        $traverser = new NodeTraverser();
        $printerMode = $config->getNested("build.print-format");
        $printerMode = isset($this->printers[$printerMode]) ? $printerMode : "shorten";
        $printer = $this->printers[$printerMode];
        if($config->getNested("preprocessing.resolve-importing", true)){
            $traverser->addVisitor(new ImportRemovingVisitor());
        }
        if($config->getNested("preprocessing.comment-optimizing", true)){
            $traverser->addVisitor(new ImportRemovingVisitor());
        }
        $traverser->addVisitor(new CommentOptimizingVisitor());
        $variableRenamer = $config->getNested("preprocessing.renaming.local-variable", "protect");
        if(isset($this->renamers[$variableRenamer])){
            $traverser->addVisitor(new LocalVariableRenamingVisitor($this->renamers[$variableRenamer]));
        }
        $propertyRenamer = $config->getNested("preprocessing.renaming.private-property", "protect");
        if(isset($this->renamers[$propertyRenamer])){
            $traverser->addVisitor(new PrivatePropertyRenamingVisitor($this->renamers[$propertyRenamer]));
        }
        $methodRenamer = $config->getNested("preprocessing.renaming.private-method", "protect");
        if(isset($this->renamers[$methodRenamer])){
            $traverser->addVisitor(new PrivateMethodRenamingVisitor($this->renamers[$methodRenamer]));
        }
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filePath)) as $path => $fileInfo){
            $fileName = $fileInfo->getFilename();
            if($fileName === "." || $fileName === "..")
                continue;

            if($config->getNested("build.include-minimal", true)){
                $inPath = substr($path, strlen($filePath));
                if($inPath !== "plugin.yml" && strpos($inPath, "src\\") !== 0 && strpos($inPath, "resources\\") !== 0)
                    continue;
            }

            $out = substr_replace($path, $buildPath, 0, strlen($filePath));
            if(!file_exists(dirname($out))){
                mkdir(dirname($out), 0777, true);
            }

            if(preg_match("/\.php$/", $path)){
                try{
                    $contents = file_get_contents($fileInfo->getPathName());
                    $stmts = $parser->parse($contents);
                    $stmts = $traverser->traverse($stmts);
                    $contents = $printer->print($stmts);
                    if($config->getNested("preprocessing.minor-optimizating", true)){
                        $contents = Utils::codeOptimize($contents);
                    }
                    file_put_contents($out, $contents);
                }catch(\Error $e){
                    echo 'Parse Error: ', $e->getMessage();
                }
            }else{
                copy($path, $out);
            }
        }

        //Build the plugin with .phar file
        $phar = new \Phar($pharPath);
        $phar->setSignatureAlgorithm(\Phar::SHA1);
        if($config->getNested("build.skip-metadata", true)){
            $phar->setMetadata($metadata);
        }
        if($config->getNested("build.skip-stub", true)){
            $phar->setStub('<?php echo "PocketMine-MP plugin ' . "{$metadata["name"]}_v{$metadata["version"]}\nThis file has been generated using BluginBuilder at " . date("r") . '\n----------------\n";if(extension_loaded("phar")){$phar = new \Phar(__FILE__);foreach($phar->getMetadata() as $key => $value){echo ucfirst($key).": ".(is_array($value) ? implode(", ", $value):$value)."\n";}} __HALT_COMPILER();');
        }else{
            $phar->setStub("<?php __HALT_COMPILER();");
        }
        $phar->startBuffering();
        $phar->buildFromDirectory($buildPath);
        if(\Phar::canCompress(\Phar::GZ)){
            $phar->compressFiles(\Phar::GZ);
        }
        $phar->stopBuffering();
    }

    /**
     * @Override for multilingual support of the config file
     * @url https://github.com/Blugin/libTranslator-PMMP/blob/master/src/blugin/lib/translator/MultilingualConfigTrait.php
     * @return bool
     */
    public function saveDefaultConfig() : bool{
        $configFile = "{$this->getDataFolder()}config.yml";
        if(file_exists($configFile))
            return false;

        $resource = $this->getResource("locale/{$this->getServer()->getLanguage()->getLang()}.yml");
        if($resource === null){
            foreach($this->getResources() as $filePath => $info){
                if(preg_match('/^locale\/[a-zA-Z]{3}\.yml$/', $filePath)){
                    $resource = $this->getResource($filePath);
                    break;
                }
            }
        }
        if($resource === null)
            return false;

        $ret = stream_copy_to_stream($resource, $fp = fopen($configFile, "wb")) > 0;
        fclose($fp);
        fclose($resource);
        return $ret;
    }
}

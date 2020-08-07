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

use blugin\tool\builder\util\Utils;
use FolderPluginLoader\FolderPluginLoader;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

class BluginBuilder extends PluginBase{
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

        $reflection = new \ReflectionClass(PluginBase::class);
        $fileProperty = $reflection->getProperty("file");
        $fileProperty->setAccessible(true);
        if(!file_exists($dataFolder = $this->getDataFolder())){
            mkdir($dataFolder, 0777, true);
        }
        foreach($plugins as $pluginName => $plugin){
            $pluginVersion = $plugin->getDescription()->getVersion();
            $pharName = "{$pluginName}_v{$pluginVersion}.phar";
            $filePath = rtrim(str_replace("\\", "/", $fileProperty->getValue($plugin)), "/") . "/";
            $this->buildPhar($plugin, $filePath, "{$dataFolder}{$pharName}");
            $sender->sendMessage("{$pharName} has been created on {$dataFolder}");
        }
        $sender->sendMessage("Complete built the {$pluginCount} plugins");
        return true;
    }

    /**
     * @param PluginBase $plugin
     * @param string     $pharPath
     * @param string     $filePath
     */
    public function buildPhar(PluginBase $plugin, string $filePath, string $pharPath) : void{
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
        $setting = $this->getConfig()->getAll();
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filePath)) as $path => $fileInfo){
            $fileName = $fileInfo->getFilename();
            if($fileName !== "." && $fileName !== ".."){
                $inPath = substr($path, strlen($filePath));
                if(!$setting["include-minimal"] || $inPath === "plugin.yml" || strpos($inPath, "src\\") === 0 || strpos($inPath, "resources\\") === 0){
                    $newFilePath = "{$buildPath}{$inPath}";
                    $newFileDir = dirname($newFilePath);
                    if(!file_exists($newFileDir)){
                        mkdir($newFileDir, 0777, true);
                    }
                    if(substr($path, -4) == ".php"){
                        $contents = \file_get_contents($path);
                        if($setting["code-optimize"]){
                            $contents = Utils::codeOptimize($contents);
                        }
                        if($setting["rename-variable"]){
                            $contents = Utils::renameVariable($contents);
                        }
                        if($setting["remove-comment"]){
                            $contents = Utils::removeComment($contents);
                        }
                        if($setting["remove-whitespace"]){
                            $contents = Utils::removeWhitespace($contents);
                        }
                        file_put_contents($newFilePath, $contents);
                    }else{
                        copy($path, $newFilePath);
                    }
                }
            }
        }

        //Build the plugin with .phar file
        $phar = new \Phar($pharPath);
        $phar->setSignatureAlgorithm(\Phar::SHA1);
        $description = $plugin->getDescription();
        if(!$setting["skip-metadata"]){
            $phar->setMetadata([
                "name" => $description->getName(),
                "version" => $description->getVersion(),
                "main" => $description->getMain(),
                "api" => $description->getCompatibleApis(),
                "depend" => $description->getDepend(),
                "description" => $description->getDescription(),
                "authors" => $description->getAuthors(),
                "website" => $description->getWebsite(),
                "creationDate" => time()
            ]);
        }
        if(!$setting["skip-stub"]){
            $phar->setStub('<?php echo "PocketMine-MP plugin ' . "{$description->getName()}_v{$description->getVersion()}\nThis file has been generated using MakePluginPlus at " . date("r") . '\n----------------\n";if(extension_loaded("phar")){$phar = new \Phar(__FILE__);foreach($phar->getMetadata() as $key => $value){echo ucfirst($key).": ".(is_array($value) ? implode(", ", $value):$value)."\n";}} __HALT_COMPILER();');
        }else{
            $phar->setStub("<?php __HALT_COMPILER();");
        }
        $phar->startBuffering();
        $phar->buildFromDirectory($buildPath);
        if($setting["compress"] && \Phar::canCompress(\Phar::GZ)){
            $phar->compressFiles(\Phar::GZ);
        }
        $phar->stopBuffering();
        Utils::removeDirectory("{$this->getDataFolder()}build/");
    }
}

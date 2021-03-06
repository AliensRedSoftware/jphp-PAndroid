<?php

use packager\Event;
use packager\Package;
use packager\cli\Console;
use php\format\YamlProcessor;
use php\io\File;
use php\io\IOException;
use php\io\Stream;
use php\lang\IllegalArgumentException;
use php\lang\IllegalStateException;
use php\lang\Process;
use php\lib\fs;
use php\lib\str;
use compress\ZipArchive;
use compress\ZipArchiveEntry;


/**
 * Class AndroidPlugin
 *
 * @jppm-task init as android:init
 * @jppm-task compile as android:compile
 * @jppm-task adb as android:compile:adb
 * @jppm-task run as android:run
 *
 * @jppm-task compile as build
 */
class AndroidPlugin {
     // paths
    public const JPHP_COMPILER_PATH = "./.jpfa/compiler.jar";
    public const JPHP_COMPILER_RESOURCE = "res://jpfa/jphp-compiler.jar";
    public const JPHP_BUILD_TEMPLATE_JAVAFX = "res://gradle-build-scripts/javafx.template.groovy";
    public const JPHP_BUILD_TEMPLATE_NATIVE = "res://gradle-build-scripts/native.template.groovy";
    public const GRADLE_WRAPPER_DIR = "./gradle/wrapper";
    public const GRADLE_WRAPPER_JAR_FILE = "./gradle/wrapper/gradle-wrapper.jar";
    public const GRADLE_WRAPPER_JAR_RESOURCE = "res://gradle/wrapper/gradle-wrapper.jar";
    public const GRADLE_WRAPPER_PROP_FILE = "./gradle/wrapper/gradle-wrapper.properties";
    public const GRADLE_WRAPPER_PROP_RESOURCE = "res://gradle/wrapper/gradle-wrapper.properties";
    public const GRADLEW_UNIX_FILE = "./gradlew";
    public const GRADLEW_UNIX_RESOURCE = "res://gradle/gradlew";
    public const GRADLEW_WIN_FILE = "./gradlew.bat";
    public const GRADLEW_WIN_RESOURCE = "res://gradle/gradlew.bat";
    public const ANDROID_JAVAFX_RESOURCES = "res://javafx-android-res.zip";
    public const ANDROID_NATIVE_RESOURCES = "res://native-android-res.zip";
    //main class start...
    public const MC = "php.runtime.launcher.Launcher";

    /**
     * Init android project
     *
     * @param Event $event
     * @throws IOException
     * @throws \php\format\ProcessorException
     */
    public function init(Event $event) {
        Console::log("Deprecated!");
        Console::log(" -> to init javafx project use `jppm init jphp-android-javafx` command");
        Console::log(" -> to init native project use `jppm init jphp-android-native` command");
    }

    /**
     * @param Event $event
     * @param string $task
     *
     * @throws IOException
     * @throws IllegalArgumentException
     * @throws IllegalStateException
     * @throws \php\format\ProcessorException
     */
    public function exec_gradle_task(Event $event, string $task) {
        //SETTINGS COMPILE
        $this->CheckEnvironment();//???????????????? ????????????????????
        $this->GInit();//?????????????????????????? gradle
        $this->JPFAC($event);//???????????????????? JPFAC...
        $this->GGBuild($event);
        Tasks::run('app:build',[],null);
        $this->JPFA($event);
        Console::log('-> starting compiler ...');
        foreach(fs::parseAs('./vendor/paths.json','json')['classPaths'][""] as $key=>$path){
            $classPath[$key]=fs::normalize(fs::abs('./vendor/').$path);
        }
        $classPath=str::join($classPath,File::PATH_SEPARATOR);
        $buildFileName="{$event->package()->getName()}-{$event->package()->getVersion('last')}";
        $execute=fs::normalize(fs::abs('./build/').fs::separator()."$buildFileName.jar").File::PATH_SEPARATOR.$classPath;
        $yaml=fs::parseAs('./'.Package::FILENAME,'yaml');
        $path=$this->CheckEnvironment($_ENV['SDK'].fs::separator().'platforms'.fs::separator().'android-'.$yaml['android']['sdk'].fs::separator().'android.jar');//???????????????? ???????? sdk
        //compile main class...
        Console::log('-> search "'.self::MC.'" process compiler  ...');
        $process = new Process([
            'java', '-cp', "{$execute}{$classPath}",
            self::MC,
            '--src', './build/out',
            '--dest', './libs/compile.jar'
        ], './');
        $exit=$process->inheritIO()->startAndWait()->getExitValue();
        if($exit!=0){
            Console::error('-> search "'.self::MC.'" process');
            die($exit);
        }else{
            Console::log(' -> success search "'.self::MC.'" process :)');
        }
        Console::log('-> starting gradle ...');

        /** @var Process $process */
        $process = (new GradlePlugin($event))->gradleProcess([
            $task
        ])->inheritIO()->startAndWait();

        exit($process->getExitValue());
    }

    /**
     * Compile project
     *
     * @param Event $event
     * @throws IOException
     * @throws IllegalArgumentException
     * @throws IllegalStateException
     * @throws \php\format\ProcessorException
     */
    public function compile(Event $event) {
        if ($event->package()->getAny('android.ui', "") == "javafx")
            $this->exec_gradle_task($event, "android");
        elseif ($event->package()->getAny('android.ui', "") == "native")
            $this->exec_gradle_task($event, "packageDebug");
        else {
            Console::error("Unable to compile unknown UI type");
            exit(103);
        }
    }

    /**
     * Compile and install project
     *
     * @param Event $event
     * @throws IOException
     * @throws IllegalArgumentException
     * @throws IllegalStateException
     * @throws \php\format\ProcessorException
     */
    public function compile_install(Event $event) {
        if ($event->package()->getAny('android.ui', "") == "javafx")
            $this->exec_gradle_task($event, "androidInstall");
        elseif ($event->package()->getAny('android.ui', "") == "native") {
            $this->exec_gradle_task($event, "installDebug");
        } else {
            Console::error("Unable to compile unknown UI type");
            exit(103);
        }
    }

    /**
     * Run project on desktop
     *
     * @jppm-dependency-of start
     *
     * @param Event $event
     * @throws IOException
     * @throws IllegalArgumentException
     * @throws IllegalStateException
     * @throws \php\format\ProcessorException
     */
    public function run(Event $event) {
        if ($event->package()->getAny('android.ui', "") == "javafx")
            $this->exec_gradle_task($event, "run");
        elseif ($event->package()->getAny('android.ui', "") == "native")
            // Soon ...
            Console::error("Running native UI type is soon ...");
        else {
            Console::error("Unable to run unknown UI type");
            exit(103);
        }

        exit(0);
    }
    /**
     * ???????????????????? jPFA (????????)
     */
    protected function JPFAC($event) {
        if(!fs::exists(self::JPHP_COMPILER_PATH)){
            Console::log('-> prepare jPFA?? compiler ...');
            fs::makeDir('./.jpfa/');
            Tasks::createFile(self::JPHP_COMPILER_PATH,fs::get(self::JPHP_COMPILER_RESOURCE));
            Console::log('-> success jPFA?? compiler :)');
        }
    }
    /**
     * ???????????????????? jPFA
     */
    protected function JPFA($event) {
        if(fs::exists(self::JPHP_COMPILER_PATH)){
            Console::log('-> prepare jPFA compiler ...');
            $buildFileName="{$event->package()->getName()}-{$event->package()->getVersion('last')}";
            Console::log('-> unpack jar');
            fs::makeDir('./build/out');
            $zip = new ZipArchive(fs::abs('./build/' . $buildFileName . '.jar'));
            $zip->readAll(function (ZipArchiveEntry $entry, ?Stream $stream) {
                if(!$entry->isDirectory()){
                    fs::makeFile(fs::abs('./build/out/' . $entry->name));
                    fs::copy($stream, fs::abs('./build/out/' . $entry->name));
                }else{
                    fs::makeDir(fs::abs('./build/out/' . $entry->name));
                }
            });
            Console::log('-> success jPFA compiler :)');
        }
    }
    /**
     * ?????? ????????????????????
     * path-????????
     */
    protected function CheckEnvironment($path=false){
        if(!$path){
            if(!$_ENV['SDK']||!is_dir($_ENV['SDK'])){
                Console::error('Environment variable SDK is not set');
                exit(101);
            }
            Console::log('-> [success] Environment variable SDK :)');
        }else{
            if(!$path||!is_file($path)){
                Console::error('Environment variable SDK-path is not valid android.jar');
                exit(101);
            }
            Console::log('-> [success] Environment variable SDK-path :)');
            return $path;
        }
        return true;
    }
    /**
     * ?????????????????????????? gradle
     */
    protected function GInit() {
        Console::log('-> install gradle ...');
        Tasks::createDir(self::GRADLE_WRAPPER_DIR);
        Tasks::createFile(self::GRADLEW_UNIX_FILE,
            str::replace(fs::get(self::GRADLEW_UNIX_RESOURCE), "\r\n", "\n"));
        Tasks::createFile(self::GRADLEW_WIN_FILE,
            fs::get(self::GRADLEW_WIN_RESOURCE));

        (new File(self::GRADLEW_UNIX_FILE))
            ->setExecutable(true);
        fs::copy(self::GRADLE_WRAPPER_JAR_RESOURCE, self::GRADLE_WRAPPER_JAR_FILE);
        fs::copy(self::GRADLE_WRAPPER_PROP_RESOURCE, self::GRADLE_WRAPPER_PROP_FILE);
    }

    protected function GGBuild(Event $event) {
        Console::log('-> prepare build.gradle ...');

        Tasks::createFile("./build.gradle");
        $template = Stream::getContents($event->package()->getAny('android.ui', "javafx") == "javafx" ?
            self::JPHP_BUILD_TEMPLATE_JAVAFX : self::JPHP_BUILD_TEMPLATE_NATIVE);

        $config = $event->package()->getAny('android', []);
        $config["sdk-path"] = str::replace($_ENV['SDK'], "\\", "\\\\");

        foreach ($config as $key => $value){
            $template = str::replace($template, "%$key%", $value);
        }

        Stream::putContents("./build.gradle", $template);
    }
}

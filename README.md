# Composer Dependency Isolation  

**What this plugin does**  

This plugin prefixes all vendor namespaces with a chosen value. This 
includes all declared namespaces, use statements, fully qualified 
references, and most string values that reference the namespace.  

All vendor code and composer autoload mappings are updated to reference 
the prefixed namespace.  

**What this plugin does not do**  

It will not touch any code in your project. It only affects code in the 
vendor directory, and it only affects code referencing the affected 
namespaces. You must update all references in your code yourself if you 
apply this to an existing project.  

**Why would I want to use this?**  

This plugin allows you to run two projects that utilize composer 
dependencies in the same runtime, without worrying about conflicting 
dependencies. The most common example is in a WordPress environment, 
where all plugins execute in the same runtime, and may rely on the same 
composer dependencies. Each project utilizing the plugin can't conflict 
with any other project unless the vendor code is not namespaced (in which 
case there aren't many options...).

## Usage  

Using the plugin is straightforward. Install the plugin by requiring it 
in your project: `composer require 0x6d617474/isolate`.

Configure the plugin by adding the following to your `composer.json`: 
```
"config" : {
    "isolate": {
      "prefix": "Your\\Prefix\\Here\\",
      "blacklist": [],
      "autorun": false,
      "require-dev": false,
      "replacements" : {}
    }
}
```

The only required value is the `prefix`.  

After you have configured the plugin, run the isolation:
```
composer isolate
composer dump
```

Your vendor code is now prefixed!  

Be sure to `dump` after you `isolate`, or your autoload mappings will 
be incorrect! 

## Configuration  

**prefix**  

This is the value that will be prepended to all vendor namespaces. It 
should be a valid namespace, and should be unique to your project. I 
recommend you don't use your main project namespace, or at least add 
`\\Vendor` to the end.  

**blacklist**  

This is a list of packages you don't want to prefix. Matching packages 
will not be scanned for namespaces, but will still have code rewritten 
if it contains namespaces from other non-blacklisted packages.  

**autorun**  

Setting this value to true automatically runs the isolation process 
before every `dump`. 

**require-dev**  

By default, only `require` packages are scanned for namespaces, and 
`require-dev` packages are ignored (as above, they will still have code 
rewritten if they contain namespaces from other packages).  

Setting this value to `true` includes the `require-dev` packages in the 
scan, and any found namespaces will be prefixed.  

**replacements**  

This is a place for manually fixing things in the vendor code that either 
were not detected and replaced, or replaced when they should not have been.   

After each file has been parsed and rewritten, if there is an entry in the 
replacements list, it will do a string replace on the file.  

String replacements should be idempotent, or things will break on multiple 
executions.  

The syntax is: 
``` 
"replacements" : {
    "path/relative/to/vendor/root/file.php" : {
        "search" : "replace",
        "search" : "replace",
    },
    "path/relative/to/vendor/root/file.php" : {
            "search" : "replace",
            "search" : "replace",
        }
}
```

=== Plugin Name ===
Contributors: Pepak
Donate link:
Tags: files, counter, count, tracking, download monitor, monitor, downloads, download
Requires at least: 2.8.0
Tested up to: 2.8.4
Stable tag: 0.05

Count the number of downloads without having to maintain a comprehensive download page.

== Description ==

I wrote Simple Download Monitor because I wanted to monitor the number of downloads of my
files without having to maintain any kind of database or making any special download links.
I just wanted to upload a file to a designated directory using FTP, provide a direct link
to it and once in a while check the number of downloads. And this is pretty much what
Simple Download Monitor does, with some slight additions, such as recording referers and
username of people who download my files.

== Installation ==

1. Create a subdirectory in your '/wp-content/plugins/' directory and extract the plugin
   there. The plugin subdirectory can be anything you like - I use 'simple-download-monitor',
   but the plugin should accept any name.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. You will see a 'Simple Download Monitor' item in your 'Settings' menu. You can enter
   three options there:
   * Allowed directories. The plugin could potentially be a huge security hole because it
     could be used to download source files of your site (e.g. 'config.php' - you definitely
     don't want to allow that!) This option restricts Simple Download Monitor to directories
     matching a regular expression. The default value of 'files/' means that Simple Download
     Monitor will only allow download of files in the '/files' directory and its subdirectories.
   * Allowed extensions. Much like 'Allowed directories', allowed extensions protect your
     site's files from unwanted downloads. It is a regular expression too and it is recommended
     to only place "safe" extensions such as 'zip' or 'jpg' here. Do not EVER allow 'php'
     extension, either directly or through wildcard (such as '.*' - that is a BIG NO-NO!).
   * Store detailed info. If this option is checked, detailed information about each download
     (such as referer, user's IP address or name, and date of download) is stored. This could
     fill your database quickly if you have a well-visited site so you can turn detailed info
     off and only keep the number of downloads and the date of last download.
1. The last step involves editing the '.htaccess' file. The default '.htaccess' skips default
   WordPress processing for existing files, which means that direct-linked files would get
   downloaded directly, without Simple Download Monitor ever learning about it. You need to
   modify the '.htaccess' file so that downloads are passed through Simple Download Monitor.
   This is easy enough to do: Open your '.htaccess' file and locate line
   `RewriteCond %{REQUEST_FILENAME} !-f`
   Add this line directly above it:
   `RewriteRule ^(files/.*) /index.php?sdmon=$1 [L]`
   (replace 'files/' with your download directory).

== Frequently Asked Questions ==

= Where are the frequently asked questions?

Nobody asked any yet.

== Screenshots ==

1. Administrative options
2. The main statistics page
3. Detailed statistics for a file

== Changelog ==

= 0.05 =
* First intentional public release.

= 0.04 =
* Accidental premature public release due to my unfamiliarity with WordPress plugin repository.

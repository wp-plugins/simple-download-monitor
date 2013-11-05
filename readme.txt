=== Simple Download Monitor ===
Contributors: Tips and Tricks HQ, Ruhul Amin, Josh
Donate link: http://www.tipsandtricks-hq.com
Tags: download, downloads, count, counter, tracker, tracking, hits, logging, monitor, manager, files, media, digital, download monitor
Requires at least: 3.0
Tested up to: 3.7.1
Stable tag: 2.1
License: GPLv2 or later

Easily manage downloadable files and monitor downloads of your digital files from your WordPress site.

== Description ==

I developed the Simple Download Monitor because I wanted to monitor the number of downloads of my files. 

This plugin is very useful for tracking your digital file download counts.

You can configure downloadable files from your WordPress admin dashboard. Then allow your visitors to download the files and this plugin will monitor which files get downloaded how many times.

The plugin will also log the IP addresses of the users who download your files.

It has a very user-friendly interface for uploading, managing and tracking downloads.

= Simple Download Monitor Features =

* Add, edit and remove downloads from an easy to use interface
* Assign categories and tags to your downloadable files
* Use shortcodes to display a download now button on a WordPress post/page
* Option to use a nice looking template to show your download now button
* Track the number of downloads for each of your files

= Simple Download Monitor Plugin Usage =

Once you have installed the plugin go to "Downloads->Settings" to configure some options

**a)** Simple Download Monitor  Settings

* Admin Options: Remove Tinymce Button - Removes the SDM Downloads button from the WP content editor (default: unchecked).
* Color Options: Download Button Color - Select a default color of the download button (default: green).

**b)** Add a new download

To configure a new download follw these steps:

1. Go to "Downloads->Add New"
1. Enter a title for your download
1. Add a description for the download
1. Select the file from your computer and upload it
1. Select an image for the download (it will be displayed as a thumbnail on the front end)
1. Publish it

You can view all of your existing downloads from the "Downloads->Downloads" menu.

**c)** Create a download button

Create a new post/page and click the "SDM Downlaods" TinyMCE button to insert a shortcode (This button will only show up if you haven't unchecked it in the settings). You can choose to display your download with a nice looking box or just a download button. For example:

`[sdm-download id="271" fancy="1"]`  (embed a download button inside a box with other information e.g. Thumbnail, Title and Description)

`[sdm-download id="271" fancy="0"]`  (embed a plain download button)

**d)** Download Logs

You can check the download stats from the "Downloads->Logs" menu. It shows the number of downloads for a particular file, IP address of the user who downloaded it, date and time.

== Installation ==

1. Go to the Add New plugins screen in your WordPress admin area
1. Click the upload tab
1. Browse for the plugin file (simple-download-monitor.zip)
1. Click Install Now and then activate the plugin

== Frequently Asked Questions ==

= Can this plugin be used to offer free downloads to the users? =

Yes.

== Screenshots ==

For screenshots please visit the plugin page

== Changelog ==

= 2.1 = 
* Minor bug fixes with the stylesheet file URL.

= 2.0 =
* Complete new architecture for the download monitor plugin
* You can download the old version with old architecture here:
http://wordpress.org/plugins/simple-download-monitor/developers/

= 0.24 and before =
* The old architecture changelog can be found inside the zip file here:
http://downloads.wordpress.org/plugin/simple-download-monitor.0.24.zip

== Upgrade Notice ==

Download monitor 2.0 uses a completely new architecture (it is much simpler and user friendly) for configuring and monitoring downloads. Read the usage section to understand how it works.

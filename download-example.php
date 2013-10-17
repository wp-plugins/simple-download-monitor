<?php 

$GLOBALS['sdmon_filename'] = $filename;
add_action('wp_head', 'sdmon_redirect');
get_header(); 

function sdmon_redirect() {
	global $sdmon_filename;
	echo "<meta http-equiv=\"refresh\" content=\"5;/${sdmon_filename}?download=true\" />\n";
}

?>
<div id="content">
	<div id="content-inner">

<div id="main">

<div align="center">
<br /><br />
<p>Your download will begin in 5 seconds!</p>
<p>If it does not, <a href="/<?php echo $filename; ?>?download=true" rel="nofollow">click here</a>.</p>
<br /><br />
</div>	
	
</div> 
<?php get_sidebar(); ?>
<?php get_footer(); ?>

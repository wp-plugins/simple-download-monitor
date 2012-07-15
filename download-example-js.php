<?php get_header(); ?>


<div id="content">
	<div id="content-inner">

<div id="main">


<div align="center">
<br /><br />
Your download will begin in 5 seconds! <br /> If it does not, <a href="/<? echo $filename; ?>?download=true" rel="nofollow">click here</a>.
<br /><br />
</div>	

		

	
</div> 
<script>
setTimeout("updateIframe()",5000);

var exec = true;

function updateIframe() { 
	if(exec == true) { 
		exec = false;
		var ifrm = document.getElementById("frame1");
		ifrm.src = "/<? echo $filename; ?>?download=true";
	}
}
</script>
<?php get_sidebar(); ?>
<iframe id="frame1" rel="nofollow" style="display:none"></iframe>
<?php get_footer(); ?>



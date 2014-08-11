<?xml version="1.0" encoding="UTF-8" ?>
<!-- Generated by Russert -->
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
	<title><?=htmlspecialchars($source->name);?></title>
	<link><?=$source->link;?></link>
	<description><?=htmlspecialchars($source->description);?></description>
	<lastBuildDate><?=date("r");?></lastBuildDate>
	<atom:link href="<?=htmlspecialchars(RSS_URL . "/" . $source->name) . ".xml";?>" rel="self" type="application/rss+xml" />
<?php foreach ($items as $item):?>
		<item>
			<title><?=htmlspecialchars($item['title'])?></title>
			<link><?=htmlspecialchars($item['link'])?></link>
			<description>
				<![CDATA[
				<?php if ($item['image']):?>
				<img src="<?=$item['image'];?>" alt="" />
				<?php endif;?>
				<?php if (!empty($item['description'])):?>
					<p><?=htmlspecialchars($item['description']);?></p>
				<?php endif;?>
				]]>
			</description>
			<?php if ($item['image']):?>
			<?php endif;?>
			<pubDate><?=date("r", $item['seen']->sec);?></pubDate>
			<guid><?=$item['guid'];?></guid>
		</item>
<?php endforeach;?>
</channel>
</rss>

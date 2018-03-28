<?xml version="1.0" encoding="UTF-8" ?>
<!-- Generated by Russert -->
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
	<title><?=htmlspecialchars($source->getName());?></title>
	<link><?=htmlspecialchars($source->getLink());?></link>
	<description><?=htmlspecialchars($source->getDescription());?></description>
	<lastBuildDate><?=date("r");?></lastBuildDate>
	<atom:link href="<?=htmlspecialchars(RSS_URL . "/" . $source->getClassName()) . ".xml";?>" rel="self" type="application/rss+xml" />
<?php foreach ($items as $item):?>
		<item>
			<title><?=htmlspecialchars($item->title)?></title>
			<link><?=htmlspecialchars($item->link)?></link>
			<description>
				<![CDATA[
				<?php if (!empty($item->image)):?>
				<img src="<?=$item->image;?>" alt="" />
				<?php endif;?>
				<?php if (!empty($item->description)):?>
					<p><?=htmlspecialchars($item->description);?></p>
				<?php endif;?>
				]]>
			</description>
			<pubDate><?=date("r", ($item->seen->__toString() / 1000));?></pubDate>
			<guid><?=htmlspecialchars($item->guid);?></guid>
		</item>
<?php endforeach;?>
</channel>
</rss>


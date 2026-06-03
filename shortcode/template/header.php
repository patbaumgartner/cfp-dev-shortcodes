<header>
    <?php include __DIR__ . '/logo.php'; ?>
    <?php include __DIR__ . '/search.php'; ?>
    <nav>
        <?php
        $class = array_filter([$_PART[0] === 'index' ? 'active' : null,]);
        $href = '.';
        ?>
        <a class="<?= implode(' ', $class); ?>" href="<?= $href; ?>">Home</a>
        <?php
        $class = array_filter([$_PART[0] === 'sponsor' ? 'active' : null,]);
        $href = './sponsor.php';
        ?>
        <a class="<?= implode(' ', $class); ?>" href="<?= $href; ?>">Sponsors</a>
        <?php
        $class = array_filter([$_PART[0] === 'newsletter' ? 'active' : null,]);
        $href = './newsletter.php';
        ?>
        <a class="<?= implode(' ', $class); ?>" href="<?= $href; ?>">Newsletter</a>
        <?php
        $class = array_filter([$_PART[0] === 'contact' ? 'active' : null,]);
        $href = './contact.php';
        ?>
        <a class="<?= implode(' ', $class); ?>" href="<?= $href; ?>">Contact</a>
    </nav>
</header>

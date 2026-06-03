<section class="cfp-schedule cfp-personal">
    <div class="cfp-subject">
        <div class="cfp-primary">
            <h2>Devoxx Belgium 2022</h2>
            <?php include __DIR__ . '/../search.php'; ?>
        </div>
        <div class="cfp-secondary">
            <nav class="cfp-tab">
                <?php $href = ''; ?>
                <a class="cfp-active" href="<?= $href; ?>">Mon 10<sup>th</sup></a>
                <?php $href = ''; ?>
                <a href="<?= $href; ?>">Tue 11<sup>th</sup></a>
                <?php $href = ''; ?>
                <a href="<?= $href; ?>">Wed 12<sup>th</sup></a>
                <?php $href = ''; ?>
                <a href="<?= $href; ?>">Thu 13<sup>th</sup></a>
                <?php $href = ''; ?>
                <a href="<?= $href; ?>">Fri 14<sup>th</sup></a>
            </nav>
            <?php $href = './schedule.php?view=general'; ?>
            <a class="cfp-button" href="<?= $href; ?>">Event Schedule</a>
        </div>
    </div>
    <?php $style = sprintf('--hour-start:%s; --hour-finish:%s;', $hour_start, $hour_finish); ?>
    <div class="cfp-area" style="<?= $style; ?>">
        <div class="cfp-scroll">
            <?php
            $hour = ($hour_finish - $hour_start);
            $time = explode(':', date('H:i', (($time_now - $time_day) - $time_start) - $time_unit));
            $offset = ($time[0] + ($time[1] / 60));
            ?>
            <div class="cfp-now" style="<?= sprintf('--hour:%s; --offset:%s;', $hour, $offset); ?>"></div>
            <div class="cfp-scope">
                <div class="cfp-column cfp-time">
                    <?php for ($a = $time_start; $a <= $time_finish; $a += ($time_unit / 6)) : ?>
                        <?php $time = ($time_day + $a); ?>
                        <time datetime="<?= date('c', $time); ?>"><?= date('H:i', $time); ?></time>
                    <?php endfor; ?>
                </div>
                <div class="cfp-column cfp-event">
                    <?php
                    $event_start = '13:00';
                    $event_finish = '14:00';
                    $event_duration = (strtotime(sprintf('00:%s', $event_finish)) - strtotime(sprintf('00:%s', $event_start)));
                    ?>
                    <article class="cfp-session" data-event-start="<?= $event_start; ?>" data-event-finish="<?= $event_finish; ?>" data-event-duration="<?= $event_duration; ?>">
                        <?php $href = './session_detail.php'; ?>
                        <a href="<?= $href; ?>">
                            <div class="cfp-content">
                                <div class="cfp-meta">
                                    <div class="cfp-favourite">591</div>
                                    <button class="cfp-button">Remove</button>
                                    <div class="cfp-track cfp-java"></div>
                                </div>
                                <div class="cfp-room">Room 1</div>
                                <div class="cfp-type">Conference</div>
                                <div class="cfp-time">
                                    <?php $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_start))); ?>
                                    <time datetime="<?= $time; ?>"><?= $event_start; ?></time>
                                    <?php $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_finish))); ?>
                                    <time datetime="<?= $time; ?>"><?= $event_finish; ?></time>
                                </div>
                                <h3>Welcome to Devoxx: Practical info</h3>
                                <div class="cfp-speaker">Stephan Janssen</div>
                            </div>
                        </a>
                    </article>
                    <?php
                    $event_start = '13:20';
                    $event_finish = '13:40';
                    $event_duration = (strtotime(sprintf('00:%s', $event_finish)) - strtotime(sprintf('00:%s', $event_start)));
                    ?>
                    <article class="cfp-session" data-event-start="<?= $event_start; ?>" data-event-finish="<?= $event_finish; ?>" data-event-duration="<?= $event_duration; ?>">
                        <?php $href = './session_detail.php'; ?>
                        <a href="<?= $href; ?>">
                            <div class="cfp-content">
                                <div class="cfp-meta">
                                    <div class="cfp-favourite">591</div>
                                    <button class="cfp-button">Remove</button>
                                    <div class="cfp-track cfp-data_ai"></div>
                                </div>
                                <div class="cfp-room">Room 1</div>
                                <div class="cfp-type">Conference</div>
                                <div class="cfp-time">
                                    <?php $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_start))); ?>
                                    <time datetime="<?= $time; ?>"><?= $event_start; ?></time>
                                    <?php $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_finish))); ?>
                                    <time datetime="<?= $time; ?>"><?= $event_finish; ?></time>
                                </div>
                                <h3>Welcome to Devoxx: Practical info</h3>
                                <div class="cfp-speaker">Stephan Janssen</div>
                            </div>
                        </a>
                    </article>
                    <?php
                    $event_start = '14:00';
                    $event_finish = '14:50';
                    $event_duration = (strtotime(sprintf('00:%s', $event_finish)) - strtotime(sprintf('00:%s', $event_start)));
                    ?>
                    <article class="cfp-recess" data-event-start="<?= $event_start; ?>" data-event-finish="<?= $event_finish; ?>" data-event-duration="<?= $event_duration; ?>">
                        <div class="cfp-content">
                            <div class="cfp-time">
                                <?php $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_start))); ?>
                                <time datetime="<?= $time; ?>"><?= $event_start; ?></time>
                                <?php $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_finish))); ?>
                                <time datetime="<?= $time; ?>"><?= $event_finish; ?></time>
                            </div>
                            <h3>Lunchbreak</h3>
                        </div>
                    </article>
                    <?php
                    $event_start = '15:00';
                    $event_finish = '17:00';
                    $event_duration = (strtotime(sprintf('00:%s', $event_finish)) - strtotime(sprintf('00:%s', $event_start)));
                    ?>
                    <article class="cfp-session" data-event-start="<?= $event_start; ?>" data-event-finish="<?= $event_finish; ?>" data-event-duration="<?= $event_duration; ?>">
                        <?php $href = './session_detail.php'; ?>
                        <a href="<?= $href; ?>">
                            <div class="cfp-content">
                                <div class="cfp-meta">
                                    <div class="cfp-favourite">591</div>
                                    <button class="cfp-button">Remove</button>
                                    <div class="cfp-track cfp-architecture"></div>
                                </div>
                                <div class="cfp-room">Room 1</div>
                                <div class="cfp-type">Conference</div>
                                <div class="cfp-time">
                                    <?php $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_start))); ?>
                                    <time datetime="<?= $time; ?>"><?= $event_start; ?></time>
                                    <?php $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_finish))); ?>
                                    <time datetime="<?= $time; ?>"><?= $event_finish; ?></time>
                                </div>
                                <h3>Welcome to Devoxx: Practical info</h3>
                                <div class="cfp-speaker">Stephan Janssen</div>
                            </div>
                        </a>
                    </article>
                </div>
            </div>
        </div>
    </div>
</section>

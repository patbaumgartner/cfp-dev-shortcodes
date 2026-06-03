jQuery(function ($) {

  // Things that need to happen when the document is ready
  $(function () {

    $("#verifyForm").hide();
    $("#authenticatedForm").hide();

    const token = window.localStorage.getItem('token');
    const talkId = $("#cfpTalkId").val();

    if (token == null) {
      $("#myScheduleActive").hide();
      $("#myScheduleNotActive").show();

      // Hide schedule button when token is not available
      $("[id=dev-cfp-talk-" + talkId + "]").hide();

    } else {

      $("#myScheduleActive").show();
      $("#myScheduleNotActive").hide();
      $("#add-schedule").show();

      // Is current talk a favourite by user?
      if (talkId !== null) {
        const storageKey = 'fav-' + talkId;
        const faved = window.localStorage.getItem(storageKey);
        if (faved) {
          const talkSelector = $("[id=dev-cfp-talk-" + talkId + "]");
          talkSelector.text("REMOVE FROM MY SCHEDULE");
        }
      }
    }
  });

  // Things that need to happen after full load
  $(window).on('load', function () {

    // *******************************************************************************************
    function setCFPRootElements() {
      const qs = document.querySelector(":root");
      qs.classList.forEach(value => {
        if (value.startsWith("cfp-")) {
          qs.classList.remove(value);
        }
      });
      qs.classList.add("cfp-page:index");
      qs.classList.add("cfp-html");
      qs.classList.add("cfp-theme:dark");
    }

    // *******************************************************************************************
    function onTalkDetailsPage() {
      const $talkId = $("[id^=cfpTalkId]");
      const $talkFrom = $("[id^=cfpTalkFrom]").val();
      const $talkExpiry = $("[id^=cfpTalkExpiry]").val();
      const $timeZone = $("[id^=cfpTimezone]");

      // TODO Handle situation where schedule info is NOT present !!!!

      if ($talkId.length > 0 && $talkFrom !== null && $talkExpiry !== null) {

        const fromInt = parseInt($talkFrom, 10) * 1000;
        const expiryInt = parseInt($talkExpiry, 10) * 1000;

        const currentTime = luxon.DateTime.now().setZone($timeZone.val());
        const from = luxon.DateTime.fromMillis(fromInt).setZone($timeZone.val());
        const expiry = luxon.DateTime.fromMillis(expiryInt).setZone($timeZone.val());

        if (currentTime > from) {
          if (currentTime < expiry) {
            $("[id^=rating-enabled]").show();
          } else {
            $("[id^=rating-disabled]").show();
            $("[id^=dev-cfp-no-rating-txt]").show();
          }
        } else {
          $("[id^=rating-disabled]").show();
          $("[id^=dev-cfp-rating-txt]").show();
        }
      }
    }

    //* *******************************************************************************************
    function onSchedulePage() {
      const $token = window.localStorage.getItem('token');
      const $tokenExp = window.localStorage.getItem('token-exp');
      const $onMySchedulePage = $("[id^=myschedule-placeholder]");

      if ($token != null) {

        // Check if token is still valid

        if (luxon.DateTime.now().toUnixInteger() > $tokenExp) {
          alert("Your authentication token has expired.  Please register again using your email.");
          window.localStorage.removeItem('token');
          window.localStorage.removeItem('token-exp');
        }

        const $onSchedulePage = $("[id^=cfp-schedule]");

        if ($onSchedulePage.length > 0) {

          const keys = Object.keys(localStorage);
          for (const key of keys) {
            if (key.startsWith('fav-')) {
              const $talkId = localStorage.getItem(key);
              $("[id=dev-cfp-talk-" + $talkId + "]").css("color", "red");
              const favCounter = $("[id=dev-cfp-talk-" + $talkId + "] > [class=cfp-favourite]");
              let value = Number(favCounter.html());
              favCounter.html(++value);
            }
          }
        } else if ($onMySchedulePage.length > 0) {
          $.ajax({
            url: the_ajax_script.ajaxurl,
            method: 'POST',
            data: {
              action: 'my_schedule',
              favs: 'GET',
              token: $token
            },
            success(response) {
              let content;

              // Remove all local fav items
              const keys = Object.keys(localStorage);
              for (const key of keys) {
                if (key.startsWith('fav-')) {
                  const $talkId = localStorage.getItem(key);
                  window.localStorage.removeItem('fav-' + $talkId);
                }
              }

              setCFPRootElements();

              content = '<main class="cfp-main">';
              content += '<section class="cfp-search">';

              content += '<div class="cfp-subject">';
              content += '  <div class="cfp-primary">';
              content += '    <div class="cfp-name">My Schedule</div>';
              content += '    <form class="cfp-search" action="search-results" method="GET"><input class="cfp-input"';
              content += '                                                                             id="dev-cfp-search-term"';
              content += '                                                                             type="search" minLength="3"';
              content += '                                                                             name="query"';
              content += '                                                                             placeholder="Search..."></form>';
              content += '  </div>';
              content += '</div>';

              content += '<div class="cfp-content">';
              if (response !== '') {
                const value = JSON.parse(response);
                value.forEach(function (item) {
                  content += '	<article class="cfp-article">';
                  content += '		<div class="cfp-foreword">';
                  content += '			<div class="cfp-name">' + item.title + '</div>';
                  content += '			<div class="cfp-type">' + item.sessionTypeName;
                  if (item.timeSlots !== undefined && item.timeSlots[0] !== undefined) {
                    content += ' - On ' + moment(item.timeSlots[0].fromDate).format("dddd") + ' from ' + moment(item.timeSlots[0].fromDate).format("HH:mm") + ' until ' + moment(item.timeSlots[0].toDate).format("HH:mm") + ' in ' + item.timeSlots[0].roomName;
                  } else {
                    content += ' - Not yet scheduled';
                  }
                  content += '      </div>';
                  content += '      <div class="cfp-track" style="background-image: url(' + item.trackImageURL + ')"></div>';
                  content += '		</div>';

                  content += '		<div class="cfp-block">';

                  item.speakers.forEach(speaker => {
                    content += '<div class="cfp-person">';
                    content += '  <a class="cfp-a" href="speaker-details/?id=' + speaker.id + '">';
                    content += '    <div class="cfp-picture" style="background-image: url(' + speaker.imageUrl + ')"></div>';
                    content += '		<div class="cfp-name">' + speaker.firstName + ' ' + speaker.lastName + '</div>';
                    if (speaker.company !== null) {
                      content += '	<div class="cfp-company">' + speaker.company + '</div>';
                    }
                    content += '  </a>';
                    content += '</div>';
                  });

                  content += '  </div>';  // End of cfp-block

                  // Add fav items received from backend
                  window.localStorage.setItem('fav-' + item.id, item.id);
                  content += '  <a class="cfp-button" href="talk/?id=' + item.id + '">More</a>';

                  content += '</article>';
                });
              } else {
                content = "<p style='color:black'>Your schedule is currently empty.</p>";
              }
              content += '</div>';
              content += '</section>';
              content += '</main>';
              $onMySchedulePage.append(content);
            }
          });
        }
      } else {
        if ($onMySchedulePage.length > 0) {

          setCFPRootElements();

          let buf;
          buf = "<main class=\"cfp-main\">";
          buf += "  <section class=\"cfp-overview\">";

          buf += "<div class=\"cfp-subject\">" +
            "<div class=\"cfp-primary\">" +
            " <div class=\"cfp-name\">My Schedule</div>" +
            "   <form class=\"cfp-search\" action=\"search-results\" method=\"GET\">" +
            "     <input class=\"cfp-input\" id=\"dev-cfp-search-term\" type=\"search\" minlength=\"3\" name=\"query\" placeholder=\"Search...\" autofocus=\"\">            " +
            "   </form>    " +
            " </div>" +
            "</div>";

          buf += "    <div class=\"cfp-block\">";
          buf += "      <div class=\"cfp-item\">";
          buf += "        <div class=\"cfp-text\">";
          buf += "          <p class=\"cfp-p\">You need to create an account to schedule or rate talks.</p>";
          buf += "          <p class=\"cfp-p\">Your favourite talks are also synced with our mobile apps when using the same email to authenticate.</p>";
          buf += "        </div>";
          buf += "        <nav class=\"cfp-link\">";
          buf += "          <a class=\"cfp-a\" href=\"register\">Register</a>";
          buf += "        </nav>";
          buf += "      </div>";
          buf += "    </div>";
          buf += "  </section>";
          buf += "</main>";

          $onMySchedulePage.append(buf);
        }
      }
    }

    // *************************************************************************************
    // Get the user's favourite talks
    function getUserFavTalks($token) {
      $.ajax({
        url: the_ajax_script.ajaxurl,
        method: 'POST',
        data: {
          action: 'favourites',
          favs: 'GET',
          token: $token
        },
        success(response) {
          if (response !== '') {
            const qs = document.querySelector(":root");
            const value = JSON.parse(response);
            value.forEach(function (item) {
              window.localStorage.setItem('fav-' + item.proposalId, item.proposalId);
              qs.classList.forEach(entry => {
                if (entry.startsWith("cfp-page:session")) {
                  const talkSelector = $("[id=dev-cfp-talk-" + item.proposalId + "]");
                  talkSelector.text("REMOVE FROM MY SCHEDULE");
                }
              });
            });
          }
        }
      });
    }

    // *******************************************************************************************
    function onActivation() {
      $("#activationSubmit").on("click", function () {
        const $email = $("#email").val();
        $.ajax({
          url: the_ajax_script.ajaxurl,
          method: 'POST',
          data: {
            action: 'activation',
            email: $email
          },
          success() {
            window.localStorage.setItem('user_email', $email);
            $("#activationForm").hide();
            $("#verifyForm").show();
            return true;
          },
          error(response) {
            alert("Error: " + response);
          }
        });
        return false;
      });
    }

    // *************************************************************************************
    function onVerify() {
      $("#verifySubmit").on("click", function () {
        const $email = window.localStorage.getItem('user_email');
        $.ajax({
          url: the_ajax_script.ajaxurl,
          method: 'POST',
          data: {
            action: 'verify',
            email: $email,
            digit: $("#digit").val(),
          },
          success(response) {
            let value;
            try {
              value = JSON.parse(response);
              window.localStorage.setItem('token', value.id_token);
              window.localStorage.setItem('token-exp', luxon.DateTime.now().plus({months: 2}));
              getUserFavTalks(value.id_token);
              $("#verifyForm").hide();
              $("#authenticatedForm").show();
            } catch (e) {
              alert("Invalid activation code, please try again.", e);
              return;
            }
            return true;
          },
          error(response) {
            alert("error: " + response);
          }
        });
        return false;
      });
    }

    // *******************************************************************************************
    function onFavTalk() {
      $('body').on('click', '[id^=dev-cfp-talk-]', function () {
        const $token = window.localStorage.getItem('token');

        const htmlTalkId = this.id;
        const $talkId = htmlTalkId.split("-")[3];

        const storageKey = 'fav-' + $talkId;

        const talkSelector = $("[id=dev-cfp-talk-" + $talkId + "]");

        const qs = document.querySelector(":root");

        if (window.localStorage.getItem(storageKey) !== null) {
          $.ajax({
            url: the_ajax_script.ajaxurl,
            method: 'POST',
            data: {
              action: 'delete_fav',
              talkId: $talkId,
              token: $token
            },
            success() {
              window.localStorage.removeItem(storageKey);

              qs.classList.forEach(value => {
                if (value.startsWith("cfp-page:session")) {
                  talkSelector.text("ADD TO MY SCHEDULE");
                }
              });

            }
          });
          return false;
        } else {
          $.ajax({
            url: the_ajax_script.ajaxurl,
            method: 'POST',
            data: {
              action: 'favourites',
              talkId: $talkId,
              token: $token
            },
            success() {
              window.localStorage.setItem(storageKey, $talkId);

              qs.classList.forEach(value => {
                if (value.startsWith("cfp-page:session")) {
                  talkSelector.text("REMOVE FROM MY SCHEDULE");
                }
              });

            }
          });
          return false;
        }
      });
    }

    // *******************************************************************************************
    function onRateTalk() {
      const $talkId = $("#dev-cfp-star-talk-id").val();
      const rating = window.localStorage.getItem('vote-' + $talkId);

      if (rating != null) {
        $("#dev-cfp-rating-txt").html("<strong>You rated " + rating + "</strong>");
      }

      // Trigger AJAX talk rating
      $("#ratingSubmit").on("click", function () {
        const currentToken = window.localStorage.getItem('token');
        // const _talkId = $("#dev-cfp-star-talk-id").val();
        // const _rating = $("#dev-cfp-star-rating").val();
        if (currentToken === null) {
          $("#loginDialog").dialog();
        } else {
          $.ajax({
            url: the_ajax_script.ajaxurl,
            method: 'POST',
            data: {
              action: 'rating',
              token: currentToken,
              $talkId,
              rating
            },
            success() {
              window.localStorage.setItem('vote-' + $talkId, rating);
              $("#dev-cfp-rating-txt").html("<strong>Thanks for voting!</strong>");
            }
          });
        }
      });
    }

    // function onThemeButtons() {
    //   $("#lightTheme").on("click", function () {
    //     window.localStorage.setItem('cfp-theme', 'light');
    //   });
    //   $("#darkTheme").on("click", function () {
    //     window.localStorage.setItem('cfp-theme', 'dark');
    //   });
    // }
    // onThemeButtons();

    onSchedulePage();

    onActivation();

    onVerify();

    onFavTalk();

    onRateTalk();

    onTalkDetailsPage();
  });
});

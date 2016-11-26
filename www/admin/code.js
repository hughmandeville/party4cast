var events = null;

var filter_day = "all";
var filter_type = "all";
var filter_feed = "all";

$(function() {
    $.ajax({
        url: "http://muchobliged.tv/party4cast/admin/party4cast_events.json",
        //url: "http://localhost/party4cast_events.json",
        cache: true
    })
    .done(function(data) {
        $("#last_updated").html(data["created"]);
        events = data["events"];
        update_results();
    });

    $("#filter_feed").on("change", function() {
        filter_feed = $("#filter_feed").val();
        update_results();
    });
});

function update_results()
{
    if (events == null) {
        // XXX: set error message
        return;
    }
    var html = '<table id="table_results">';
    html += '<tr><th>Image</th><th>Event</th><th>Start Time</th><th>End Time</th><th>Type</th><th>Feed</th><th>Price</th></tr>';
    var total = events.length;
    var shown = 0;
    for (i in events) {
        var event = events[i];
        if (filter_feed != "all") {
            if (event['feed'] != filter_feed) {
                continue;
            }
        }

        var event_img = '';
        if ((event['image'] != null) && (event['image'] != "")) {
            event_img = '<a href="' + event['url'] + '"><img class="img_event" src="' + event['image'] + '"/></a>';
        }

        var event_name = event['name'];
        if (event['url'] != "") {
            event_name = '<a href="' + event['url'] + '">' + event['name'] + '</a>';
        }

        var event_feed = get_feed_link(event['feed']);
        var event_type = event['type'];
        html += '<tr>' +
            '<td>' + event_img + '</td>' +
            '<td>' + event_name  + '</td>' +
            '<td>' + event['start_time'] + '</td>' +
            '<td>' + event['end_time'] + '</td>' +
            '<td>' + event_type + '</td>' +
            '<td>' + event_feed + '</td>' +
            '<td>' + event['price'] + '</td>' +
            '</tr>';
        shown++;
    }

    html += '</table>';
    $("#results").html(html);
    $("#num_events").html(shown + "/" + total);
}

function get_feed_link(feed)
{
    var url = null;
    if (feed == "Eventbrite Parties NYC") {
        return ('<a href="https://www.eventbrite.com/d/ny--new-york/parties/?crt=regular&sort=best"><img class="logo_feed" src="images/logo_eventbrite.png"/></a>' +
               '<a href="https://www.eventbrite.com/d/ny--new-york/parties/?crt=regular&sort=best">Eventbrite Parties NYC</a>');
    } else if (feed == "NightOut NYC") {
        return ('<a href="https://nightout.com/ny/new-york"><img class="logo_feed" src="images/logo_nightout.png"/></a>' +
               '<a href="https://nightout.com/ny/new-york">NightOut NYC</a>');
    } else if (feed == "NYC.com") {
        return ('<a href="http://www.nyc.com/bars_clubs_music/"><img class="logo_feed" src="images/logo_nyc_com.png"/></a>' +
               '<a href="http://www.nyc.com/bars_clubs_music/">NYC.com</a>');
    }
    return (feed);
}


var events = null;

var filter_day = "all";
var filter_type = "all";
var filter_feed = "all";

var sort_by = "name";
var sort_order = 1;

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
    $("#filter_type").on("change", function() {
        filter_type = $("#filter_type").val();
        update_results();
    });
    $("#nav_menu").on("click", function() {
        $("#menu_panel").css('left', $(this).position().left - 212);
        $("#menu_panel").toggle();
    });
    $(window).resize(function() {
        $("#menu_panel").hide();
    });
});

function update_results()
{
    if (events == null) {
        $("#results").html("No events");
        return;
    }

    events.sort(compare_events);
    
    var html = '<table id="table_results">';
    var event_sort_str = "";
    var type_sort_str = "";
    var feed_sort_str = "";
    var arrow_str = '<i class="material-icons">arrow_drop_down</i>';
    if (sort_order == -1) {
        arrow_str = '<i class="material-icons">arrow_drop_up</i>';
    }
    if (sort_by == "name") {
        event_sort_str = arrow_str;
    } else if (sort_by == "type") {
        type_sort_str = arrow_str;
    } else if (sort_by == "feed") {
        feed_sort_str = arrow_str;
    }
    
    html += '<tr><th>Image</th>' +
        '<th data-sort-by="name">Event' + event_sort_str + '</th>' +
        '<th>Start Time</th>' +
        '<th>End Time</th>' +
        '<th data-sort-by="type">Type' + type_sort_str + '</th>' +
        '<th data-sort-by="feed">Feed' + feed_sort_str + '</th>' +
        '<th>Price</th></tr>';
    var total = events.length;
    var shown = 0;
    for (i in events) {
        var event = events[i];
        if (filter_feed != "all") {
            if (event['feed'] != filter_feed) {
                continue;
            }
        }
        if (filter_type != "all") {
            if (event['type'] != filter_type) {
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
        if ((event_type != null) || (event_type != "")) {
            event_type = '<span class="event_type">'  + event['type'] + '</span><br/><span class="event_type2">' + event['type2'] + '</span>';
        }
        html += '<tr>' +
            '<td>' + event_img + '</td>' +
            '<td>' + event_name  + '</td>' +
            '<td>' + event['start_time'] + '</td>' +
            '<td>' + event['end_time'] + '</td>' +
            '<td>' + event_type + '</td>' +
            '<td class="event_feed">' + event_feed + '</td>' +
            '<td>' + event['price'] + '</td>' +
            '</tr>';
        shown++;
    }

    html += '</table>';
    $("#results").html(html);
    $("#num_events").html(shown + "/" + total);

    $("th[data-sort-by]").on("click", function() {
        var new_sort_by = $(this).data("sort-by");
        if (new_sort_by == sort_by) {
            sort_order *= -1;
            update_results();
        } else {
            sort_by = new_sort_by;
            update_results();
        }
    });
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
    } else if (feed = "NYC Go") {
        return ('<a href="http://www.nycgo.com/things-to-do/nightlife"><img class="logo_feed" src="images/logo_nyc_go.png"/></a>' +
               '<a href="http://www.nycgo.com/things-to-do/nightlife">NYC Go</a>');
    }
    return (feed);
}



function compare_events(e1, e2)
{
    if (e1[sort_by].trim() < e2[sort_by].trim()) {
        return (-1 * sort_order);
    }
    if (e1[sort_by].trim() > e2[sort_by].trim()) {
        return (1 * sort_order);
    }


    if (e1['name'].trim() < e2['name'].trim()) {
        return (-1);
    }
    if (e1['name'].trim() > e2['name'].trim()) {
        return (1);
    }

    
    return (0);
}

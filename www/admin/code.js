var events = null;

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
});

function update_results()
{
    var html = '<table id="table_results">';
    html += '<tr><th>Image</th><th>Event</th><th>Start Time</th><th>End Time</th><th>Price</th><th>Type</th><th>Feed</th></tr>';
    for (i in events) {
        var event = events[i];
        var event_img = '';
        if ((event['image'] != null) && (event['image'] != "")) {
            event_img = '<a href="' + event['url'] + '"><img class="img_event" src="' + event['image'] + '"/></a>';
        }

        var event_name = event['name'];
        if (event['url'] != "") {
            event_name = '<a href="' + event['url'] + '">' + event['name'] + '</a>';
        }
        var event_type = event['type'];
        html += '<tr>' +
            '<td>' + event_img + '</td>' +
            '<td>' + event_name  + '</td>' +
            '<td>' + event['start_time'] + '</td>' +
            '<td>' + event['end_time'] + '</td>' +
            '<td>' + event['price'] + '</td>' +
            '<td>' + event_type + '</td>' +
            '<td>' + event['feed'] + '</td>' +
            '</tr>';
    }

    html += '</table>';
    $("#results").html(html);
}


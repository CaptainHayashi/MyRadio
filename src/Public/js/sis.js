var SIS = function(container) {
    var sisContainer = container,
        tabContainer = document.createElement('div'),
        tabTabsContainer = document.createElement('ul'),
        tabContentContainer = document.createElement('div'),
        pluginContainer = document.createElement('div'),
        defaultActiveFound = false,
        params = {},
        callbacks = {},
        /**
        * Starts the AJAX Comet request to the server. Will call itself after the
        * first time it is run. When the request is complete, it will call the
        * required callback functions from plugins.
        */
        connect = function() {
            $.ajax({
                url: myury.makeURL('SIS', 'remote'),
                method: 'POST',
                data: params,
                cache: false,
                dataType: 'json',
                //The timeout here is to prevent stack overflow
                complete: function() {setTimeout(connect, 100);},
                success: handleResponse
            });
        },
        /**
        * Used by connect, this function deals with the JSON object returned from the
        * server
        * @param data The JSON object returned from the server
        */
        handleResponse = function(data) {
            for (var namespace in data) {
                //Handle the Debug namespace - log the message
                if (namespace == 'debug') {
                    for (var message in data[namespace]) {
                        console.log(data[namespace][message]);
                    }
                    continue;
                } else if (typeof(callbacks[namespace]) != 'undefined') {
                    //This namespace is registered. Execute the callback function
                    callbacks[namespace](data[namespace]);
                }
            }
        },
        generateTabContainer = function(id, name) {
            var tabTab = document.createElement('li'),
                tabLink = document.createElement('a'),
                tabBadge = document.createElement('span');

            tabTab.setAttribute('role', 'presentation');
            tabLink.setAttribute('role', 'tab');
            tabLink.setAttribute('data-toggle', 'tab');
            tabLink.setAttribute('href', '#' + id);
            tabLink.innerHTML = name + '&nbsp;';
            tabBadge.setAttribute('class', 'badge');
            tabLink.appendChild(tabBadge);
            tabTab.appendChild(tabLink);
            tabTabsContainer.appendChild(tabTab);

            var container = document.createElement('div');
            container.setAttribute('class', 'tab-pane');
            container.setAttribute('role', 'tabpanel');
            container.setAttribute('id', id);
            tabContentContainer.appendChild(container);

            $(tabLink).click(function(e) {
                e.preventDefault();
                $(this).tab('show');
            });

            container.setUnread = function(num) {
                if (num == 0) {
                    tabBadge.innerHTML = '';
                } else {
                    tabBadge.innerHTML = num;
                }
            },

            container.registerParam = function(key, value) {
                params[key] = value;
            }

            return {
                container: container,
                link: tabLink
            };
        },
        generatePluginContainer = function(id, name) {
            var panel = document.createElement('div'),
                heading = document.createElement('div'),
                title = document.createElement('h4'),
                titleLink = document.createElement('a'),
                titleBadge = document.createElement('span'),
                contentHolder = document.createElement('div'),
                content = document.createElement('div');

            panel.setAttribute('class', 'panel panel-default');

            // Sets up panel header
            heading.setAttribute('class', 'panel-heading');
            heading.setAttribute('role', 'tab');
            heading.setAttribute('id', 'heading-' + id);
            title.setAttribute('class', 'panel-title');
            titleLink.setAttribute('data-toggle', 'collapse');
            titleLink.setAttribute('data-parent', '#sis-plugincontainer');
            titleLink.setAttribute('href', '#collapse-' + id);
            titleLink.setAttribute('aria-expanded', 'false');
            titleLink.setAttribute('aria-controls', 'collapse-' + id);
            titleLink.innerHTML = name + '&nbsp;';
            titleBadge.setAttribute('class', 'badge');
            titleLink.appendChild(titleBadge);
            title.appendChild(titleLink);
            heading.appendChild(title);
            panel.appendChild(heading);

            // Sets up panel content
            contentHolder.setAttribute('id', 'collapse-' + id);
            contentHolder.setAttribute('class', 'panel-collapse collapse');
            contentHolder.setAttribute('role', 'tabpanel');
            contentHolder.setAttribute('aria-labelledby', 'heading-' + id);
            content.setAttribute('class', 'panel-body');
            contentHolder.appendChild(content);
            panel.appendChild(contentHolder);

            pluginContainer.appendChild(panel);
            $(contentHolder).collapse({toggle:false});

            content.setUnread = function(num) {
                if (num == 0) {
                    titleBadge.innerHTML = '';
                } else {
                    titleBadge.innerHTML = num;
                }
            },

            content.registerParam = function(key, value) {
                params[key] = value;
            }

            content.hide = function() {
                $(contentHolder).collapse('hide');
            }

            content.show = function() {
                $(contentHolder).collapse('show');
            }

            return {
                container: content,
                link: titleLink
            };
        };

    tabContainer.setAttribute('class', 'sis-tabcontainer col-md-9');
    tabTabsContainer.setAttribute('class', 'nav nav-tabs');
    tabTabsContainer.setAttribute('role', 'tablist');
    tabContainer.appendChild(tabTabsContainer);
    tabContentContainer.setAttribute('class', 'tab-content');
    tabContainer.appendChild(tabContentContainer);

    pluginContainer.setAttribute('class', 'sis-plugincontainer col-md-3 panel-group');
    pluginContainer.setAttribute('role', 'tablist');
    sisContainer.appendChild(pluginContainer);
    sisContainer.appendChild(tabContainer);

    connect();

    return {
        registerModule: function(id, module, type) {
            if (
                !module.hasOwnProperty('initialise') ||
                !module.hasOwnProperty('name') ||
                !module.hasOwnProperty('type')
            ) {
                console.error('Cannot load ' + id + ' as it is invalid.');
                return;
            }

            var objs;
            if (module.type == 'tab') {
                objs = generateTabContainer(id, module.name);
            } else if (module.type == 'plugin') {
                objs = generatePluginContainer(id, module.name);
            }
            // Make it the active module if it is set to be
            if (
                defaultActiveFound === false &&
                module.hasOwnProperty('activeByDefault') &&
                module.activeByDefault
            ) {
                defaultActiveFound = true;
                $(objs.link).click();
            }

            if (module.hasOwnProperty('update')) {
                callbacks[id] = function(data) {
                    module.update.call(objs.container, data);
                }
            }

            module.initialise.call(objs.container, objs);
        }
    };
};


/**
* Old code below - remove when SIS bootstrapping is done
*/
var dontcallme = function(){


/* News */
    var news_url = myury.makeURL('SIS','news')+"NewsRoom.aspx";


/* Schedule */
    function updateSchedule() {
        $.getJSON(myury.makeURL('SIS','schedule.get'), function(data) {
            var currentStart = new Date(data.current.start_time*1000);
            var currentEnd = new Date(data.current.end_time*1000);
            $("#schedule-onair .showTime").text(formatTime(currentStart)+' - '+formatTime(currentEnd));
            $("#schedule-onair .showName").text(data.current.title);
            $("#schedule-onair .showPeople").text(data.current.presenters);
            $("#schedule-onair .showDesc").html(data.current.desc);

            $('#schedule-next').html('');
            data.next.forEach(function(e, i, data) {
                $('#schedule-next').append('<div id="schedule-item-'+i+'" class="schedule-item"> \
                    <hgroup class="clearfix"> \
                        <h3 class="showTime"></h3> \
                        <h3 class="showName"></h3> \
                        <h5 class="showPeople"></h5> \
                    </hgroup> \
                <div class="showDesc"></div></div>');
                var nextStart = new Date(data[i].start_time*1000);
                var nextEnd = new Date(data[i].end_time*1000);
                $("#schedule-item-"+i+" .showTime").text(formatTime(nextStart)+' - '+formatTime(nextEnd));
                $("#schedule-item-"+i+" .showName").text(data[i].title);
                $("#schedule-item-"+i+" .showPeople").text(data[i].presenters);
                $("#schedule-item-"+i+" .showDesc").html(data[i].desc);
            });
            $('.schedule-item:not(:first-child)').prepend('<hr>');
        });
    }

    function formatTime(d) {
        var HH = d.getHours();
        if (HH < 10) {
            HH = '0' + HH;
        }

        var MM = d.getMinutes();
        if (MM < 10) {
            MM = '0' + MM;
        }

        if (isNaN(HH) || isNaN(MM)) {
            return "";
        }
        return HH + ':' + MM;
    }


/* Tracklist */
    var tracklist_highest_id = 0;

    var updateTrackListing = function(data) {
        for (var i in data) {
            $('#tracklist-data').append('<div id="delsure'+data[i]['id']+'" title="Delete Track?">Are you sure you want to delete this track?</div>');

            var trackDate = new Date(data[i]['playtime']*1000);
            var secs = trackDate.getSeconds();
            if (secs < 10) {
                secs = "0" + secs;
            }
            var mins = trackDate.getMinutes();
            if (mins < 10) {
                mins = "0" + mins;
            }
            var month = trackDate.getMonth()+1;
            if (month < 10) {
                month = "0" + month;
            }
            var time = trackDate.getHours()+':'+mins+':'+secs+' '+trackDate.getDate()+'/'+month;
            //Add the new row to the top of the tracklist table
            $('#tracklist table').prepend(
                '<tr class="tlist-item" id="t'+data[i]['id']+'"> \
                    <td>'+time+'</td> \
                    <td>'+data[i]['title']+'</td> \
                    <td>'+data[i]['artist']+'</td> \
                    <td>'+data[i]['album']+'</td> \
                    <td class="delbutton"><span class="ui-icon ui-icon-trash" style="display:inline-block"></span></td></tr>');

            tracklist_highest_id = (tracklist_highest_id < data[i]['id']) ? data[i]['id'] : tracklist_highest_id;

            $('#t'+data[i]['id']+' .delbutton').click(function() {
                var id = $(this).parent().attr('id').replace('t', '');
                $('#delsure'+id).dialog({
                    resizable: false,
                    modal: true,
                    buttons: {
                        "Yes": function(){
                                $.ajax({
                                    url: myury.makeURL('SIS','tracklist.delTrack', {'id': id})
                                });
                                $('#t'+id).hide();
                                $('#delsure'+id).dialog("close");

                        },
                        Cancel: function(){
                                $('#delsure'+id).dialog("close");
                        }
                    }
                });
            });

            server.register_param('tracklist_highest_id', tracklist_highest_id);
        }
    };

    function submitTrackCancel() {
        $("#trackpick-tname").val("");
        $("#trackpick-album").val("");
        $("#trackpick-artist").val("");
        $("#tracklist-insert").dialog("close");
        $("#tracklist-insert-check").dialog("close");
    }

    function submitTrackNoLib() {
        var tname = $("#trackpick-tname").val();
        var album = $("#trackpick-album").val();
        var artist = $("#trackpick-artist").val();
        $.ajax({
            url: myury.makeURL('SIS','tracklist.checkTrack'),
            data: {tname: tname, album: album, artist: artist, where: "notrec"},
            type: 'get',
            dataType: 'json',
            success: function(output) {
                console.log(output);
                submitTrackCancel();
            }
        });
    }

    function submitTrack() {
        //  event.preventDefault();
        var tname = $("#trackpick-tname").val();
        var album = $("#trackpick-album").val();
        var artist = $("#trackpick-artist").val();
        $.ajax({
                url: myury.makeURL('SIS','tracklist.checkTrack'),
            data: {tname: tname, album: album, artist: artist, where: 'rec'},
            type: 'get',
            dataType: 'json',
            success: function(output){
                console.log(output);
                if (output.return == '1') {
                    $("#tracklist-insert-check").dialog("open");
                    $("#warntitle").text(tname);
                    $("#warnartist").text(artist);
                }
                if (output.return == '2') {
                }
                if (output.return == '0') {
                    submitTrackCancel();
                }
            }
        });
        return false;
    }


$(document).ready(function() {

    // News
    $(window).load(function() {
            setTimeout(function(){
                $('#ury-irn').attr('src', news_url);
            }, 2000);
        });


    // Schedule
    setInterval('updateSchedule()', 60000);
    updateSchedule();


    // Tracklist
    server.register_callback(updateTrackListing, 'tracklist');
    server.register_param('tracklist_highest_id', tracklist_highest_id);
    $('#tracklist-insert').dialog({
        autoOpen: false,
        height: 420,
        width: 350,
        modal: true,
        buttons: {
            "Submit": function() {
                submitTrack();
            },
        },
        close: function() {
            submitTrackCancel();
        }
    });

    $('#tracklist-insert-check').dialog({
        autoOpen: false,
        modal: true,
        buttons: {
            "Yes": function() {
                submitTrackNoLib();
            },
            "No": function() {
                $(this).dialog("close");
            }
        }
    });

    $( "#trackpick-artist" ).autocomplete({
        source: function (request, response) {
            var tname = $("#trackpick-tname").val();
            var album = $("#trackpick-album").val();
            var artist = $("#trackpick-artist").val();
            var box = "artist";
            $.getJSON(myury.makeURL('SIS','tracklist.findTrack'),
            {
                tname: tname,
                album: album,
                artist: artist,
                box: box
            }, response);
        }
    });

    $( "#trackpick-album" ).autocomplete({
        source: function (request, response) {
            var tname = $("#trackpick-tname").val();
            var album = $("#trackpick-album").val();
            var artist = $("#trackpick-artist").val();
            var box = "album";
            $.getJSON(myury.makeURL('SIS','tracklist.findTrack'),
            {
                tname: tname,
                album: album,
                artist: artist,
                box: box
            }, response);
        }
    });

    $( "#trackpick-tname" ).autocomplete({
        source: function (request, response) {
            var tname = $("#trackpick-tname").val();
            var album = $("#trackpick-album").val();
            var artist = $("#trackpick-artist").val();
            var box = "tname";
            $.getJSON(myury.makeURL('SIS','tracklist.findTrack'),
            {
                tname: tname,
                album: album,
                artist: artist,
                box: box
            }, response);
        }
    });

    $('#add-track').click(function() {
        $('#tracklist-insert').dialog("open");
    });

});

};

vcl 4.1;

backend default {
    .host = "nginx";
    .port = "80";
}

sub vcl_recv {
    if (req.method != "GET" && req.method != "HEAD") {
        return(pass);
    }

    if (req.method == "PURGE") {
        return (purge);
    }

    return (hash);
}

sub vcl_backend_response {
    set beresp.ttl = 120s;
    set beresp.grace = 30s;

    if (beresp.http.Cache-Control ~ "no-cache|no-store|private") {
        set beresp.uncacheable = true;
    }

    return (deliver);
}

sub vcl_deliver {
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }
}
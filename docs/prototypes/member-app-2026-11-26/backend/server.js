
const http = require("http");
const fs = require("fs");
const path = require("path");
const { URL } = require("url");


// =========================================
// FRONTEND LOCATION
// =========================================

const frontendPath = path.join(__dirname, "..", "frontend");


// =========================================
// TEST STUDENT DATA
// =========================================
//
// This is ONLY for development.
// Later, this will come from the real HR system.
//

const students = {

    "FK-1001": {
        studentId: "FK-1001",
        name: "Hana Bekele",
        grade: "7ኛ",
        class: "መካከለኛ"
    },

    "FK-1002": {
        studentId: "FK-1002",
        name: "Daniel Tesfaye",
        grade: "8ኛ",
        class: "ወጣት"
    },

    "FK-1003": {
        studentId: "FK-1003",
        name: "Ruth Alemu",
        grade: "6ኛ",
        class: "ሕፃናት"
    }

};


// =========================================
// HELPER: SEND JSON
// =========================================

function sendJSON(res, statusCode, data) {

    res.writeHead(statusCode, {
        "Content-Type": "application/json; charset=utf-8",
        "Access-Control-Allow-Origin": "*"
    });

    res.end(JSON.stringify(data));

}


// =========================================
// HELPER: FIND FILE TYPE
// =========================================

function getContentType(filePath) {

    const extension = path.extname(filePath).toLowerCase();

    const types = {

        ".html": "text/html; charset=utf-8",
        ".css": "text/css; charset=utf-8",
        ".js": "text/javascript; charset=utf-8",
        ".json": "application/json; charset=utf-8",
        ".png": "image/png",
        ".jpg": "image/jpeg",
        ".jpeg": "image/jpeg",
        ".gif": "image/gif",
        ".svg": "image/svg+xml",
        ".pdf": "application/pdf"

    };

    return types[extension] || "application/octet-stream";

}


// =========================================
// CREATE SERVER
// =========================================

const server = http.createServer((req, res) => {

    const requestUrl = new URL(
        req.url,
        `http://${req.headers.host || "localhost"}`
    );


    // =====================================
    // STUDENT API
    // =====================================
    //
    // Example:
    //
    // /api/student/FK-1001
    //

    if (
        requestUrl.pathname.startsWith("/api/student/")
        && req.method === "GET"
    ) {

        const studentId =
            decodeURIComponent(
                requestUrl.pathname.replace(
                    "/api/student/",
                    ""
                )
            );


        const student =
            students[studentId];


        // Student not found

        if (!student) {

            sendJSON(res, 404, {

                success: false,

                message: "Student not found."

            });

            return;

        }


        // Student found

        sendJSON(res, 200, {

            success: true,

            student: student

        });

        return;

    }


    // =====================================
    // NORMAL FRONTEND FILES
    // =====================================

    let filePath;


    if (
        requestUrl.pathname === "/"
        || requestUrl.pathname === "/index.html"
    ) {

        filePath =
            path.join(
                frontendPath,
                "index.html"
            );

    } else {

        filePath =
            path.join(
                frontendPath,
                requestUrl.pathname
            );

    }


    // =====================================
    // READ FRONTEND FILE
    // =====================================

    fs.readFile(filePath, (error, content) => {

        if (error) {

            res.writeHead(404, {
                "Content-Type": "text/plain; charset=utf-8"
            });

            res.end("Page not found.");

            return;

        }


        res.writeHead(200, {

            "Content-Type":
                getContentType(filePath)

        });


        res.end(content);

    });

});


// =========================================
// SERVER PORT
// =========================================

const PORT = 3000;


// =========================================
// START SERVER
// =========================================

server.listen(PORT, () => {

    console.log(
        `ፈለገ ቅዱሳን is running at http://localhost:${PORT}`
    );

});


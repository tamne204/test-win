#define UNICODE
#define _UNICODE
#include <windows.h>
#include <iostream>
#include <string>
#include <vector>

std::wstring GetModuleDir() {
    wchar_t buf[MAX_PATH] = {0};
    GetModuleFileNameW(NULL, buf, MAX_PATH);
    std::wstring path(buf);
    size_t pos = path.find_last_of(L"\\/");
    if (pos != std::wstring::npos) {
        return path.substr(0, pos);
    }
    return L".";
}

bool FileExists(const std::wstring& path) {
    DWORD attr = GetFileAttributesW(path.c_str());
    return (attr != INVALID_FILE_ATTRIBUTES && !(attr & FILE_ATTRIBUTE_DIRECTORY));
}

bool DirExists(const std::wstring& path) {
    DWORD attr = GetFileAttributesW(path.c_str());
    return (attr != INVALID_FILE_ATTRIBUTES && (attr & FILE_ATTRIBUTE_DIRECTORY));
}

int main(int argc, char* argv[]) {
    // Check for direct ping / version flag
    for (int i = 1; i < argc; ++i) {
        if (std::string(argv[i]) == "--version" || std::string(argv[i]) == "-v") {
            std::cout << "{\"version\": \"2.0.0\", \"product_id\": \"2toolne-autoedit\", \"platform\": \"win32\", \"arch\": \"x64\"}\n";
            return 0;
        }
        if (std::string(argv[i]) == "--ping") {
            std::cout << "{\"pong\": true, \"protocol\": \"v1\", \"status\": \"READY\"}\n";
            return 0;
        }
    }

    std::wstring moduleDir = GetModuleDir();
    
    // Candidate Python interpreters
    std::vector<std::wstring> pythonCandidates = {
        moduleDir + L"\\_internal\\python.exe",
        moduleDir + L"\\python.exe",
        moduleDir + L"\\..\\..\\..\\..\\.venv\\Scripts\\python.exe",
        moduleDir + L"\\..\\..\\..\\.venv\\Scripts\\python.exe",
        L"python.exe"
    };

    // Candidate sidecar scripts
    std::vector<std::wstring> scriptCandidates = {
        moduleDir + L"\\sidecar_main.py",
        moduleDir + L"\\sidecar\\desktop_bridge\\sidecar_main.py",
        moduleDir + L"\\..\\sidecar\\desktop_bridge\\sidecar_main.py",
        moduleDir + L"\\..\\..\\desktop_bridge\\sidecar_main.py",
        moduleDir + L"\\..\\..\\..\\desktop_bridge\\sidecar_main.py"
    };

    std::wstring chosenPython = L"python.exe";
    for (const auto& p : pythonCandidates) {
        if (FileExists(p)) {
            chosenPython = p;
            break;
        }
    }

    std::wstring chosenScript = L"";
    for (const auto& s : scriptCandidates) {
        if (FileExists(s)) {
            chosenScript = s;
            break;
        }
    }

    // Build command line
    std::wstring cmdLine = L"\"" + chosenPython + L"\"";
    if (!chosenScript.empty()) {
        cmdLine += L" \"" + chosenScript + L"\"";
    }
    for (int i = 1; i < argc; ++i) {
        int len = MultiByteToWideChar(CP_UTF8, 0, argv[i], -1, NULL, 0);
        if (len > 0) {
            std::vector<wchar_t> warg(len);
            MultiByteToWideChar(CP_UTF8, 0, argv[i], -1, &warg[0], len);
            cmdLine += L" \"" + std::wstring(&warg[0]) + L"\"";
        }
    }

    STARTUPINFOW si;
    PROCESS_INFORMATION pi;
    ZeroMemory(&si, sizeof(si));
    si.cb = sizeof(si);
    si.dwFlags = STARTF_USESTDHANDLES;
    si.hStdInput = GetStdHandle(STD_INPUT_HANDLE);
    si.hStdOutput = GetStdHandle(STD_OUTPUT_HANDLE);
    si.hStdError = GetStdHandle(STD_ERROR_HANDLE);
    ZeroMemory(&pi, sizeof(pi));

    std::vector<wchar_t> cmdLineBuf(cmdLine.begin(), cmdLine.end());
    cmdLineBuf.push_back(L'\0');

    BOOL success = CreateProcessW(
        NULL,
        &cmdLineBuf[0],
        NULL,
        NULL,
        TRUE, // Inherit handles (pipes)
        0,
        NULL,
        NULL,
        &si,
        &pi
    );

    if (!success) {
        DWORD err = GetLastError();
        std::cerr << "Failed to spawn Python sidecar process. Error code: " << err << std::endl;
        return 1;
    }

    WaitForSingleObject(pi.hProcess, INFINITE);

    DWORD exitCode = 0;
    GetExitCodeProcess(pi.hProcess, &exitCode);

    CloseHandle(pi.hProcess);
    CloseHandle(pi.hThread);

    return (int)exitCode;
}

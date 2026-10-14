package main

import (
	"bytes"
	"strings"
	"testing"
	"time"
)

func TestIndexTemplateRendersThreadAndLockedContent(t *testing.T) {
	tpl, err := parseTemplates()
	if err != nil {
		t.Fatalf("parse template: %v", err)
	}

	data := PageData{
		AppName: "Threadline",
		Boards:  []Board{{ID: 1, Slug: "general", Name: "General"}},
		Board:   Board{ID: 1, Slug: "general", Name: "General"},
		User:    &User{ID: 2, Username: "reader", Credits: 9},
		CSRF:    "test-token",
		Threads: []Post{{
			ID: 3, Subject: "Useful post", Author: "author", Body: "hello", CreatedAt: time.Now(),
			IsThread: true, Anchor: "t3", ThreadLink: "/?board=general&thread=3",
			Unlock: &Unlockable{ID: 4, ContentType: "tutorial", Title: "A guide", Price: 3},
		}},
		MaxUploadMB: 5,
	}

	var output bytes.Buffer
	if err := tpl.ExecuteTemplate(&output, "index.html", data); err != nil {
		t.Fatalf("render template: %v", err)
	}
	for _, expected := range []string{"Useful post", "A guide", "Unlock for 3 credits", `name="csrf" value="test-token"`, `hx-boost="true"`} {
		if !strings.Contains(output.String(), expected) {
			t.Errorf("rendered page does not contain %q", expected)
		}
	}
}

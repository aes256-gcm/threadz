package main

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"embed"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"html/template"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"
	"golang.org/x/crypto/bcrypt"
)

const (
	threadReward   = 2
	replyReward    = 1
	dailyEarnLimit = 5
)

//go:embed templates/index.html database/schema.sql public/assets/style.css
var files embed.FS

var usernamePattern = regexp.MustCompile(`^[A-Za-z0-9_]{3,24}$`)
var boardPattern = regexp.MustCompile(`^[a-z0-9]{1,16}$`)

type App struct {
	pool          *pgxpool.Pool
	template      *template.Template
	secret        []byte
	uploadDir     string
	maxUpload     int64
	secureCookies bool
}

type User struct {
	ID       int64
	Username string
	Credits  int
}

type Board struct {
	ID          int64
	Slug        string
	Name        string
	Description string
}

type Unlockable struct {
	ID          int64
	CreatorID   int64
	ContentType string
	Title       string
	Content     string
	Price       int
	HasAccess   bool
}

type Post struct {
	ID         int64
	Subject    string
	Author     string
	Body       string
	ImagePath  string
	ImageName  string
	CreatedAt  time.Time
	ReplyCount int
	IsThread   bool
	BoardSlug  string
	Unlock     *Unlockable
	ThreadLink string
	Anchor     string
}

type Flash struct {
	Type    string `json:"type"`
	Message string `json:"message"`
}

type PageData struct {
	AppName      string
	Boards       []Board
	Board        Board
	User         *User
	EarnedToday  int
	CSRF         string
	Flash        *Flash
	ThreadID     int64
	ActiveThread *Post
	Threads      []Post
	Replies      []Post
	NotFound     bool
	MaxUploadMB  int64
}

type lockedContent struct {
	Kind    string
	Title   string
	Content string
	Price   int
}

type userMessage struct{ message string }

func (e userMessage) Error() string { return e.message }

func main() {
	ctx := context.Background()
	databaseURL := env("DATABASE_URL", "")
	if !strings.HasPrefix(databaseURL, "postgres://") && !strings.HasPrefix(databaseURL, "postgresql://") {
		databaseURL = fmt.Sprintf("postgres://%s:%s@%s:%s/%s",
			url.QueryEscape(env("DB_USER", "threadline")),
			url.QueryEscape(env("DB_PASSWORD", "threadline")),
			env("DB_HOST", "localhost"), env("DB_PORT", "5432"), env("DB_NAME", "threadline"))
	}

	var pool *pgxpool.Pool
	var err error
	for attempt := 0; attempt < 60; attempt++ {
		pool, err = pgxpool.New(ctx, databaseURL)
		if err == nil {
			err = pool.Ping(ctx)
		}
		if err == nil {
			break
		}
		if pool != nil {
			pool.Close()
		}
		time.Sleep(time.Second)
	}
	if err != nil {
		log.Fatalf("database unavailable: %v", err)
	}
	defer pool.Close()

	schema, err := files.ReadFile("database/schema.sql")
	if err != nil {
		log.Fatal(err)
	}
	if _, err := pool.Exec(ctx, string(schema)); err != nil {
		log.Fatalf("apply schema: %v", err)
	}

	tpl, err := parseTemplates()
	if err != nil {
		log.Fatal(err)
	}

	maxUpload, _ := strconv.ParseInt(env("MAX_UPLOAD_MB", "5"), 10, 64)
	if maxUpload < 1 {
		maxUpload = 5
	}
	uploadDir := env("UPLOAD_DIR", "public/uploads")
	if err := os.MkdirAll(uploadDir, 0o755); err != nil {
		log.Fatal(err)
	}
	secret := env("SESSION_SECRET", "local-development-secret-change-me")
	if env("APP_ENV", "development") == "production" && len(secret) < 32 {
		log.Fatal("SESSION_SECRET must contain at least 32 characters in production")
	}

	app := &App{
		pool: pool, template: tpl, secret: []byte(secret), uploadDir: uploadDir,
		maxUpload: maxUpload * 1024 * 1024, secureCookies: env("APP_ENV", "development") == "production",
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /assets/style.css", app.style)
	mux.Handle("GET /uploads/", http.StripPrefix("/uploads/", http.FileServer(http.Dir(uploadDir))))
	mux.HandleFunc("POST /action", app.action)
	mux.HandleFunc("GET /", app.index)

	server := &http.Server{
		Addr: ":" + env("PORT", "8080"), Handler: securityHeaders(mux),
		ReadHeaderTimeout: 10 * time.Second, ReadTimeout: 20 * time.Second,
		WriteTimeout: 30 * time.Second, IdleTimeout: 60 * time.Second,
	}
	log.Printf("Threadline listening on %s", server.Addr)
	log.Fatal(server.ListenAndServe())
}

func parseTemplates() (*template.Template, error) {
	return template.New("index.html").Funcs(template.FuncMap{
		"date": func(value time.Time) string {
			location, loadErr := time.LoadLocation("Australia/Sydney")
			if loadErr != nil {
				location = time.UTC
			}
			return value.In(location).Format("02/01/06(Mon) 15:04")
		},
		"dict": func(values ...any) map[string]any {
			result := make(map[string]any, len(values)/2)
			for index := 0; index+1 < len(values); index += 2 {
				key, ok := values[index].(string)
				if ok {
					result[key] = values[index+1]
				}
			}
			return result
		},
		"plural": func(value int, singular, plural string) string {
			if value == 1 {
				return singular
			}
			return plural
		},
	}).ParseFS(files, "templates/index.html")
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}

func securityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("Referrer-Policy", "same-origin")
		w.Header().Set("Content-Security-Policy", "default-src 'self'; script-src 'self' https://unpkg.com; img-src 'self' data:; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
		next.ServeHTTP(w, r)
	})
}

func (a *App) style(w http.ResponseWriter, _ *http.Request) {
	css, err := files.ReadFile("public/assets/style.css")
	if err != nil {
		http.Error(w, "not found", http.StatusNotFound)
		return
	}
	w.Header().Set("Content-Type", "text/css; charset=utf-8")
	w.Header().Set("Cache-Control", "public, max-age=3600")
	_, _ = w.Write(css)
}

func (a *App) index(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, err := a.currentUser(ctx, r)
	if err != nil {
		a.serverError(w, err)
		return
	}
	data, err := a.pageData(ctx, r, user)
	if err != nil {
		a.serverError(w, err)
		return
	}
	data.CSRF = a.csrfToken(w, r)
	data.Flash = a.takeFlash(w, r)
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	if data.NotFound {
		w.WriteHeader(http.StatusNotFound)
	}
	if err := a.template.ExecuteTemplate(w, "index.html", data); err != nil {
		log.Printf("render: %v", err)
	}
}

func (a *App) pageData(ctx context.Context, r *http.Request, user *User) (PageData, error) {
	data := PageData{AppName: env("APP_NAME", "Threadline"), User: user, MaxUploadMB: a.maxUpload / 1024 / 1024}
	rows, err := a.pool.Query(ctx, `SELECT id, slug, name, description FROM boards ORDER BY name`)
	if err != nil {
		return data, err
	}
	for rows.Next() {
		var board Board
		if err := rows.Scan(&board.ID, &board.Slug, &board.Name, &board.Description); err != nil {
			rows.Close()
			return data, err
		}
		data.Boards = append(data.Boards, board)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return data, err
	}
	if len(data.Boards) == 0 {
		return data, errors.New("no boards configured")
	}

	slug := r.URL.Query().Get("board")
	if slug == "" {
		slug = "general"
	}
	data.Board = data.Boards[0]
	for _, board := range data.Boards {
		if board.Slug == slug {
			data.Board = board
			break
		}
	}
	data.ThreadID, _ = strconv.ParseInt(r.URL.Query().Get("thread"), 10, 64)
	viewerID := int64(0)
	if user != nil {
		viewerID = user.ID
		data.EarnedToday, err = a.earnedToday(ctx, user.ID)
		if err != nil {
			return data, err
		}
	}

	if data.ThreadID > 0 {
		post, err := a.getThread(ctx, data.Board, data.ThreadID, viewerID)
		if errors.Is(err, pgx.ErrNoRows) {
			data.NotFound = true
			return data, nil
		}
		if err != nil {
			return data, err
		}
		data.ActiveThread = &post
		data.Replies, err = a.getReplies(ctx, data.ThreadID, viewerID)
		return data, err
	}
	data.Threads, err = a.getThreads(ctx, data.Board, viewerID)
	return data, err
}

func (a *App) getThread(ctx context.Context, board Board, id, viewerID int64) (Post, error) {
	var post Post
	err := a.pool.QueryRow(ctx, `SELECT t.id, t.subject, COALESCE(u.username,t.author), t.body, COALESCE(t.image_path,''), COALESCE(t.image_name,''), t.created_at, COUNT(r.id)::int
		FROM threads t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN replies r ON r.thread_id=t.id
		WHERE t.id=$1 AND t.board_id=$2 GROUP BY t.id,u.username`, id, board.ID).
		Scan(&post.ID, &post.Subject, &post.Author, &post.Body, &post.ImagePath, &post.ImageName, &post.CreatedAt, &post.ReplyCount)
	if err != nil {
		return post, err
	}
	post.IsThread, post.Anchor, post.BoardSlug = true, fmt.Sprintf("t%d", post.ID), board.Slug
	post.Unlock, err = a.getUnlock(ctx, "thread_id", post.ID, viewerID)
	return post, err
}

func (a *App) getThreads(ctx context.Context, board Board, viewerID int64) ([]Post, error) {
	rows, err := a.pool.Query(ctx, `SELECT t.id,t.subject,COALESCE(u.username,t.author),t.body,COALESCE(t.image_path,''),COALESCE(t.image_name,''),t.created_at,COUNT(r.id)::int
		FROM threads t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN replies r ON r.thread_id=t.id
		WHERE t.board_id=$1 GROUP BY t.id,u.username ORDER BY t.bumped_at DESC LIMIT 30`, board.ID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var posts []Post
	for rows.Next() {
		var post Post
		if err := rows.Scan(&post.ID, &post.Subject, &post.Author, &post.Body, &post.ImagePath, &post.ImageName, &post.CreatedAt, &post.ReplyCount); err != nil {
			return nil, err
		}
		post.IsThread, post.Anchor, post.BoardSlug = true, fmt.Sprintf("t%d", post.ID), board.Slug
		post.ThreadLink = fmt.Sprintf("/?board=%s&thread=%d", url.QueryEscape(board.Slug), post.ID)
		post.Unlock, err = a.getUnlock(ctx, "thread_id", post.ID, viewerID)
		if err != nil {
			return nil, err
		}
		posts = append(posts, post)
	}
	return posts, rows.Err()
}

func (a *App) getReplies(ctx context.Context, threadID, viewerID int64) ([]Post, error) {
	rows, err := a.pool.Query(ctx, `SELECT r.id,COALESCE(u.username,r.author),r.body,COALESCE(r.image_path,''),COALESCE(r.image_name,''),r.created_at
		FROM replies r LEFT JOIN users u ON u.id=r.user_id WHERE r.thread_id=$1 ORDER BY r.created_at,r.id`, threadID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var posts []Post
	for rows.Next() {
		var post Post
		if err := rows.Scan(&post.ID, &post.Author, &post.Body, &post.ImagePath, &post.ImageName, &post.CreatedAt); err != nil {
			return nil, err
		}
		post.Anchor = fmt.Sprintf("r%d", post.ID)
		post.Unlock, err = a.getUnlock(ctx, "reply_id", post.ID, viewerID)
		if err != nil {
			return nil, err
		}
		posts = append(posts, post)
	}
	return posts, rows.Err()
}

func (a *App) getUnlock(ctx context.Context, column string, postID, viewerID int64) (*Unlockable, error) {
	if column != "thread_id" && column != "reply_id" {
		return nil, errors.New("invalid post type")
	}
	var item Unlockable
	err := a.pool.QueryRow(ctx, fmt.Sprintf(`SELECT x.id,x.creator_id,x.content_type,x.title,x.content,x.price,
		(x.creator_id=$1 OR EXISTS(SELECT 1 FROM unlock_purchases p WHERE p.unlockable_id=x.id AND p.buyer_id=$1)) FROM unlockables x WHERE x.%s=$2`, column), viewerID, postID).
		Scan(&item.ID, &item.CreatorID, &item.ContentType, &item.Title, &item.Content, &item.Price, &item.HasAccess)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	return &item, err
}

func (a *App) action(w http.ResponseWriter, r *http.Request) {
	r.Body = http.MaxBytesReader(w, r.Body, a.maxUpload+1024*1024)
	var err error
	if strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/") {
		err = r.ParseMultipartForm(1024 * 1024)
	} else {
		err = r.ParseForm()
	}
	if err != nil {
		a.fail(w, r, userMessage{"Request or upload is too large."})
		return
	}
	if !a.validCSRF(r) {
		http.Error(w, "Your session expired. Refresh and try again.", http.StatusForbidden)
		return
	}

	user, err := a.currentUser(r.Context(), r)
	if err != nil {
		a.serverError(w, err)
		return
	}
	path := returnPath(r)
	var savedUpload string

	switch r.FormValue("action") {
	case "register":
		err = a.register(r.Context(), w, r)
	case "login":
		err = a.login(r.Context(), w, r)
	case "logout":
		a.clearCookie(w, "threadline_session")
		a.setFlash(w, "success", "Signed out.")
	case "unlock":
		err = a.purchase(r.Context(), user, r.FormValue("unlock_id"))
	case "new_thread":
		var imagePath, imageName string
		imagePath, imageName, savedUpload, err = a.saveUpload(r)
		var message string
		if err == nil {
			message, err = a.newThread(r.Context(), user, r, imagePath, imageName)
		}
		if err == nil {
			a.setFlash(w, "success", message)
		}
	case "reply":
		var imagePath, imageName string
		imagePath, imageName, savedUpload, err = a.saveUpload(r)
		var message string
		if err == nil {
			message, err = a.reply(r.Context(), user, r, imagePath, imageName)
		}
		if err == nil {
			a.setFlash(w, "success", message)
		}
	default:
		err = userMessage{"Unknown action."}
	}
	if err != nil {
		if savedUpload != "" {
			_ = os.Remove(savedUpload)
		}
		a.fail(w, r, err)
		return
	}
	if r.FormValue("action") == "unlock" {
		a.setFlash(w, "success", "Content unlocked.")
	}
	a.redirect(w, r, path)
}

func (a *App) register(ctx context.Context, w http.ResponseWriter, r *http.Request) error {
	username := strings.TrimSpace(r.FormValue("username"))
	password := r.FormValue("password")
	if !usernamePattern.MatchString(username) {
		return userMessage{"Username must be 3–24 letters, numbers, or underscores."}
	}
	if len(password) < 8 || len(password) > 128 {
		return userMessage{"Password must be 8–128 characters."}
	}
	hash, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return err
	}
	var id int64
	err = a.pool.QueryRow(ctx, `INSERT INTO users(username,password_hash) VALUES($1,$2) RETURNING id`, username, string(hash)).Scan(&id)
	var pgErr *pgconn.PgError
	if errors.As(err, &pgErr) && pgErr.Code == "23505" {
		return userMessage{"That username is already taken."}
	}
	if err != nil {
		return err
	}
	a.setSession(w, id)
	a.setFlash(w, "success", "Account created. Earn credits by posting.")
	return nil
}

func (a *App) login(ctx context.Context, w http.ResponseWriter, r *http.Request) error {
	var id int64
	var hash string
	err := a.pool.QueryRow(ctx, `SELECT id,password_hash FROM users WHERE LOWER(username)=LOWER($1)`, strings.TrimSpace(r.FormValue("username"))).Scan(&id, &hash)
	if errors.Is(err, pgx.ErrNoRows) {
		return userMessage{"Username or password is incorrect."}
	}
	if err != nil {
		return err
	}
	if bcrypt.CompareHashAndPassword([]byte(hash), []byte(r.FormValue("password"))) != nil {
		return userMessage{"Username or password is incorrect."}
	}
	a.setSession(w, id)
	a.setFlash(w, "success", "Signed in.")
	return nil
}

func (a *App) newThread(ctx context.Context, user *User, r *http.Request, imagePath, imageName string) (string, error) {
	if user == nil {
		return "", userMessage{"Sign in to create a thread."}
	}
	body := limitedText(r.FormValue("body"), 8000)
	if body == "" {
		return "", userMessage{"Comment is required."}
	}
	subject := limitedText(r.FormValue("subject"), 120)
	locked, err := parseLocked(r)
	if err != nil {
		return "", err
	}
	board, err := a.boardBySlug(ctx, r.FormValue("board"))
	if err != nil {
		return "", userMessage{"Board not found."}
	}
	tx, err := a.pool.Begin(ctx)
	if err != nil {
		return "", err
	}
	defer tx.Rollback(ctx)
	var id int64
	err = tx.QueryRow(ctx, `INSERT INTO threads(board_id,user_id,subject,author,body,image_path,image_name) VALUES($1,$2,$3,$4,$5,NULLIF($6,''),NULLIF($7,'')) RETURNING id`, board.ID, user.ID, subject, user.Username, body, imagePath, imageName).Scan(&id)
	if err != nil {
		return "", err
	}
	if err = createLocked(ctx, tx, user.ID, id, 0, locked); err != nil {
		return "", err
	}
	earned, err := award(ctx, tx, user.ID, threadReward, "thread_reward", id)
	if err != nil {
		return "", err
	}
	if err = tx.Commit(ctx); err != nil {
		return "", err
	}
	if earned > 0 {
		return fmt.Sprintf("Thread posted. You earned %d credits.", earned), nil
	}
	return "Thread posted. Daily credit limit already reached.", nil
}

func (a *App) reply(ctx context.Context, user *User, r *http.Request, imagePath, imageName string) (string, error) {
	if user == nil {
		return "", userMessage{"Sign in to reply."}
	}
	threadID, _ := strconv.ParseInt(r.FormValue("thread_id"), 10, 64)
	if threadID < 1 {
		return "", userMessage{"Thread not found."}
	}
	body := limitedText(r.FormValue("body"), 8000)
	if body == "" {
		return "", userMessage{"Comment is required."}
	}
	locked, err := parseLocked(r)
	if err != nil {
		return "", err
	}
	tx, err := a.pool.Begin(ctx)
	if err != nil {
		return "", err
	}
	defer tx.Rollback(ctx)
	var exists int64
	if err = tx.QueryRow(ctx, `SELECT id FROM threads WHERE id=$1 FOR UPDATE`, threadID).Scan(&exists); errors.Is(err, pgx.ErrNoRows) {
		return "", userMessage{"Thread not found."}
	} else if err != nil {
		return "", err
	}
	var id int64
	err = tx.QueryRow(ctx, `INSERT INTO replies(thread_id,user_id,author,body,image_path,image_name) VALUES($1,$2,$3,$4,NULLIF($5,''),NULLIF($6,'')) RETURNING id`, threadID, user.ID, user.Username, body, imagePath, imageName).Scan(&id)
	if err != nil {
		return "", err
	}
	if err = createLocked(ctx, tx, user.ID, 0, id, locked); err != nil {
		return "", err
	}
	earned, err := award(ctx, tx, user.ID, replyReward, "reply_reward", id)
	if err != nil {
		return "", err
	}
	if _, err = tx.Exec(ctx, `UPDATE threads SET bumped_at=NOW() WHERE id=$1`, threadID); err != nil {
		return "", err
	}
	if err = tx.Commit(ctx); err != nil {
		return "", err
	}
	if earned > 0 {
		return "Reply posted. You earned 1 credit.", nil
	}
	return "Reply posted. Daily credit limit already reached.", nil
}

func (a *App) purchase(ctx context.Context, user *User, rawID string) error {
	if user == nil {
		return userMessage{"Sign in to unlock content."}
	}
	id, _ := strconv.ParseInt(rawID, 10, 64)
	if id < 1 {
		return userMessage{"Locked content not found."}
	}
	tx, err := a.pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx)
	var creatorID int64
	var price int
	if err = tx.QueryRow(ctx, `SELECT creator_id,price FROM unlockables WHERE id=$1 FOR UPDATE`, id).Scan(&creatorID, &price); errors.Is(err, pgx.ErrNoRows) {
		return userMessage{"Locked content not found."}
	} else if err != nil {
		return err
	}
	if creatorID == user.ID {
		return tx.Commit(ctx)
	}
	var exists bool
	err = tx.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM unlock_purchases WHERE unlockable_id=$1 AND buyer_id=$2)`, id, user.ID).Scan(&exists)
	if err != nil {
		return err
	}
	if exists {
		return tx.Commit(ctx)
	}
	ids := []int64{user.ID, creatorID}
	if ids[0] > ids[1] {
		ids[0], ids[1] = ids[1], ids[0]
	}
	if _, err = tx.Exec(ctx, `SELECT id FROM users WHERE id=ANY($1) ORDER BY id FOR UPDATE`, ids); err != nil {
		return err
	}
	result, err := tx.Exec(ctx, `UPDATE users SET credits=credits-$1 WHERE id=$2 AND credits >= $1`, price, user.ID)
	if err != nil {
		return err
	}
	if result.RowsAffected() != 1 {
		return userMessage{"You do not have enough credits."}
	}
	if _, err = tx.Exec(ctx, `UPDATE users SET credits=credits+$1 WHERE id=$2`, price, creatorID); err != nil {
		return err
	}
	if _, err = tx.Exec(ctx, `INSERT INTO unlock_purchases(unlockable_id,buyer_id,price_paid) VALUES($1,$2,$3)`, id, user.ID, price); err != nil {
		return err
	}
	if _, err = tx.Exec(ctx, `INSERT INTO credit_transactions(user_id,amount,kind,reference_id,note) VALUES($1,$2,'unlock_purchase',$3,'Unlocked paid content'),($4,$5,'unlock_sale',$3,'Sold paid content')`, user.ID, -price, id, creatorID, price); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

func award(ctx context.Context, tx pgx.Tx, userID int64, wanted int, kind string, referenceID int64) (int, error) {
	var ignored int64
	if err := tx.QueryRow(ctx, `SELECT id FROM users WHERE id=$1 FOR UPDATE`, userID).Scan(&ignored); err != nil {
		return 0, err
	}
	var earned int
	if err := tx.QueryRow(ctx, `SELECT COALESCE(SUM(amount),0)::int FROM credit_transactions WHERE user_id=$1 AND kind IN ('thread_reward','reply_reward') AND created_at >= date_trunc('day',NOW())`, userID).Scan(&earned); err != nil {
		return 0, err
	}
	amount := wanted
	if amount > dailyEarnLimit-earned {
		amount = dailyEarnLimit - earned
	}
	if amount < 1 {
		return 0, nil
	}
	if _, err := tx.Exec(ctx, `UPDATE users SET credits=credits+$1 WHERE id=$2`, amount, userID); err != nil {
		return 0, err
	}
	_, err := tx.Exec(ctx, `INSERT INTO credit_transactions(user_id,amount,kind,reference_id,note) VALUES($1,$2,$3,$4,$5)`, userID, amount, kind, referenceID, "Posting reward")
	return amount, err
}

func createLocked(ctx context.Context, tx pgx.Tx, creatorID, threadID, replyID int64, item *lockedContent) error {
	if item == nil {
		return nil
	}
	var thread, reply any
	if threadID > 0 {
		thread = threadID
	}
	if replyID > 0 {
		reply = replyID
	}
	_, err := tx.Exec(ctx, `INSERT INTO unlockables(creator_id,thread_id,reply_id,content_type,title,content,price) VALUES($1,$2,$3,$4,$5,$6,$7)`, creatorID, thread, reply, item.Kind, item.Title, item.Content, item.Price)
	return err
}

func parseLocked(r *http.Request) (*lockedContent, error) {
	if r.FormValue("add_unlock") != "1" {
		return nil, nil
	}
	kind := r.FormValue("unlock_type")
	if kind != "tutorial" && kind != "link" && kind != "download" {
		return nil, userMessage{"Choose a valid locked content type."}
	}
	title := limitedText(r.FormValue("unlock_title"), 120)
	content := limitedText(r.FormValue("unlock_content"), 12000)
	price, _ := strconv.Atoi(r.FormValue("unlock_price"))
	if title == "" || content == "" {
		return nil, userMessage{"Locked content needs a title and content."}
	}
	if price < 1 || price > 100 {
		return nil, userMessage{"Unlock price must be between 1 and 100 credits."}
	}
	if kind != "tutorial" {
		parsed, err := url.ParseRequestURI(content)
		if err != nil || (parsed.Scheme != "http" && parsed.Scheme != "https") {
			return nil, userMessage{"Locked links must be complete HTTP or HTTPS URLs."}
		}
	}
	return &lockedContent{Kind: kind, Title: title, Content: content, Price: price}, nil
}

func limitedText(value string, max int) string {
	value = strings.TrimSpace(value)
	runes := []rune(value)
	if len(runes) > max {
		return string(runes[:max])
	}
	return value
}

func (a *App) saveUpload(r *http.Request) (string, string, string, error) {
	file, header, err := r.FormFile("image")
	if errors.Is(err, http.ErrMissingFile) {
		return "", "", "", nil
	}
	if err != nil {
		return "", "", "", userMessage{"The image could not be uploaded."}
	}
	defer file.Close()
	data, err := io.ReadAll(io.LimitReader(file, a.maxUpload+1))
	if err != nil {
		return "", "", "", err
	}
	if int64(len(data)) > a.maxUpload {
		return "", "", "", userMessage{"The image is larger than the upload limit."}
	}
	mime := http.DetectContentType(data)
	extensions := map[string]string{"image/jpeg": "jpg", "image/png": "png", "image/gif": "gif", "image/webp": "webp"}
	ext, ok := extensions[mime]
	if !ok {
		return "", "", "", userMessage{"Upload a JPG, PNG, GIF, or WebP image."}
	}
	random := make([]byte, 16)
	if _, err = rand.Read(random); err != nil {
		return "", "", "", err
	}
	name := hex.EncodeToString(random) + "." + ext
	disk := filepath.Join(a.uploadDir, name)
	if err = os.WriteFile(disk, data, 0o644); err != nil {
		return "", "", "", err
	}
	original := limitedText(filepath.Base(header.Filename), 255)
	return "/uploads/" + name, original, disk, nil
}

func (a *App) currentUser(ctx context.Context, r *http.Request) (*User, error) {
	value, ok := a.readSignedCookie(r, "threadline_session")
	if !ok {
		return nil, nil
	}
	parts := strings.Split(value, "|")
	if len(parts) != 2 {
		return nil, nil
	}
	id, _ := strconv.ParseInt(parts[0], 10, 64)
	expires, _ := strconv.ParseInt(parts[1], 10, 64)
	if id < 1 || time.Now().Unix() > expires {
		return nil, nil
	}
	var user User
	err := a.pool.QueryRow(ctx, `SELECT id,username,credits FROM users WHERE id=$1`, id).Scan(&user.ID, &user.Username, &user.Credits)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	return &user, err
}

func (a *App) earnedToday(ctx context.Context, userID int64) (int, error) {
	var value int
	err := a.pool.QueryRow(ctx, `SELECT COALESCE(SUM(amount),0)::int FROM credit_transactions WHERE user_id=$1 AND kind IN ('thread_reward','reply_reward') AND created_at >= date_trunc('day',NOW())`, userID).Scan(&value)
	return value, err
}

func (a *App) boardBySlug(ctx context.Context, slug string) (Board, error) {
	var board Board
	err := a.pool.QueryRow(ctx, `SELECT id,slug,name,description FROM boards WHERE slug=$1`, slug).Scan(&board.ID, &board.Slug, &board.Name, &board.Description)
	return board, err
}

func (a *App) setSession(w http.ResponseWriter, userID int64) {
	a.setSignedCookie(w, "threadline_session", fmt.Sprintf("%d|%d", userID, time.Now().Add(30*24*time.Hour).Unix()), 30*24*time.Hour, true)
}

func (a *App) csrfToken(w http.ResponseWriter, r *http.Request) string {
	if value, ok := a.readSignedCookie(r, "threadline_csrf"); ok {
		return value
	}
	random := make([]byte, 32)
	_, _ = rand.Read(random)
	token := hex.EncodeToString(random)
	a.setSignedCookie(w, "threadline_csrf", token, 24*time.Hour, true)
	return token
}

func (a *App) validCSRF(r *http.Request) bool {
	value, ok := a.readSignedCookie(r, "threadline_csrf")
	return ok && value != "" && hmac.Equal([]byte(value), []byte(r.FormValue("csrf")))
}

func (a *App) setFlash(w http.ResponseWriter, kind, message string) {
	data, _ := json.Marshal(Flash{Type: kind, Message: message})
	a.setSignedCookie(w, "threadline_flash", string(data), 2*time.Minute, true)
}

func (a *App) takeFlash(w http.ResponseWriter, r *http.Request) *Flash {
	value, ok := a.readSignedCookie(r, "threadline_flash")
	if !ok {
		return nil
	}
	a.clearCookie(w, "threadline_flash")
	var flash Flash
	if json.Unmarshal([]byte(value), &flash) != nil {
		return nil
	}
	return &flash
}

func (a *App) setSignedCookie(w http.ResponseWriter, name, value string, age time.Duration, httpOnly bool) {
	payload := base64.RawURLEncoding.EncodeToString([]byte(value))
	mac := hmac.New(sha256.New, a.secret)
	_, _ = mac.Write([]byte(payload))
	signed := payload + "." + hex.EncodeToString(mac.Sum(nil))
	http.SetCookie(w, &http.Cookie{Name: name, Value: signed, Path: "/", MaxAge: int(age.Seconds()), Expires: time.Now().Add(age), HttpOnly: httpOnly, Secure: a.secureCookies, SameSite: http.SameSiteLaxMode})
}

func (a *App) readSignedCookie(r *http.Request, name string) (string, bool) {
	cookie, err := r.Cookie(name)
	if err != nil {
		return "", false
	}
	parts := strings.Split(cookie.Value, ".")
	if len(parts) != 2 {
		return "", false
	}
	mac := hmac.New(sha256.New, a.secret)
	_, _ = mac.Write([]byte(parts[0]))
	want, err := hex.DecodeString(parts[1])
	if err != nil || !hmac.Equal(mac.Sum(nil), want) {
		return "", false
	}
	decoded, err := base64.RawURLEncoding.DecodeString(parts[0])
	if err != nil {
		return "", false
	}
	return string(decoded), true
}

func (a *App) clearCookie(w http.ResponseWriter, name string) {
	http.SetCookie(w, &http.Cookie{Name: name, Value: "", Path: "/", MaxAge: -1, HttpOnly: true, Secure: a.secureCookies, SameSite: http.SameSiteLaxMode})
}

func (a *App) fail(w http.ResponseWriter, r *http.Request, err error) {
	var message userMessage
	if errors.As(err, &message) {
		a.setFlash(w, "error", message.Error())
	} else {
		log.Printf("request failed: %v", err)
		a.setFlash(w, "error", "Something went wrong. Please try again.")
	}
	a.redirect(w, r, returnPath(r))
}

func (a *App) redirect(w http.ResponseWriter, r *http.Request, path string) {
	if r.Header.Get("HX-Request") == "true" {
		w.Header().Set("HX-Redirect", path)
		w.WriteHeader(http.StatusNoContent)
		return
	}
	http.Redirect(w, r, path, http.StatusSeeOther)
}

func (a *App) serverError(w http.ResponseWriter, err error) {
	log.Printf("server error: %v", err)
	http.Error(w, "Something went wrong.", http.StatusInternalServerError)
}

func returnPath(r *http.Request) string {
	slug := r.FormValue("board")
	if !boardPattern.MatchString(slug) {
		slug = "general"
	}
	path := "/?board=" + url.QueryEscape(slug)
	if threadID, _ := strconv.ParseInt(r.FormValue("thread"), 10, 64); threadID > 0 {
		path += fmt.Sprintf("&thread=%d", threadID)
	}
	return path
}
